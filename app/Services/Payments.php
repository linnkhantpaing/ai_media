<?php

namespace App\Services;

use App\Models\CustomerAccountEvent;
use App\Models\CustomerPackageAssignment;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodVersion;
use App\Models\PaymentReceipt;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Payments
{
    public const STATUSES = ['awaiting_payment', 'pending_review', 'under_review', 'needs_information', 'approved', 'rejected', 'expired', 'cancelled'];

    public function money(string|int|null $value, string $field = 'received'): int
    {
        $this->require(is_string($value) || is_int($value), $field, 'Enter an amount.');
        $this->require((bool) preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', (string) $value), $field, 'Use a positive amount with at most two decimal places.');
        $parts = explode('.', (string) $value);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public function status(PaymentTransaction $payment): string
    {
        return $payment->status === 'awaiting_payment' && $payment->expires_at->isPast() ? 'expired' : $payment->status;
    }

    public function totals(PaymentTransaction $payment): array
    {
        $completed = (int) $payment->refunds->where('status', 'completed')->sum('amount_minor');
        $reserved = (int) $payment->refunds->whereIn('status', ['requested', 'under_review'])->sum('amount_minor');

        return ['completed' => $completed, 'reserved' => $reserved, 'remaining' => max(0, $payment->expected_minor - $completed - $reserved), 'state' => $completed === 0 ? 'none' : ($completed === $payment->expected_minor ? 'full' : 'partial')];
    }

    public function present(PaymentTransaction $p, bool $full = false): array
    {
        $p->loadMissing(['user', 'refunds']);
        $status = $this->status($p);
        $receiving = $p->receiving_snapshot['receiving'];
        $identifier = (string) ($receiving['details']['identifier'] ?? '');
        $actions = match ($status) {
            'awaiting_payment','needs_information' => ['evidence', 'cancel'],'pending_review' => ['review', 'information', 'reject', 'approve'],'under_review' => ['information', 'reject', 'approve'],default => []
        };
        if (in_array($status, ['awaiting_payment', 'pending_review', 'under_review', 'needs_information'], true) && $p->subscription_id === null) {
            $actions[] = 'edit';
        }
        $totals = $this->totals($p);
        if ($status === 'approved' && $totals['remaining'] > 0) {
            $actions[] = 'refund';
        }
        $result = ['id' => $p->id, 'code' => 'PYT-'.str_pad((string) $p->id, 7, '0', STR_PAD_LEFT), 'revision' => $p->revision, 'customer' => ['id' => $p->user_id, 'name' => $p->user->name, 'email' => $p->user->email, 'status' => $p->user->account_status],
            'package' => $p->package_snapshot, 'purpose' => $p->purpose, 'currency' => $p->currency, 'expected_minor' => $p->expected_minor, 'received_minor' => $p->received_minor, 'status' => $status,
            'method' => ['id' => $p->payment_method_id, 'name' => $receiving['name'], 'provider' => $receiving['provider'], 'type' => $receiving['type'], 'masked_identifier' => $identifier === '' ? '' : str_repeat('*', 6).(mb_strlen($identifier) > 4 ? mb_substr($identifier, -4) : '')],
            'submitted_at' => $p->submitted_at?->toIso8601String(), 'created_at' => $p->created_at->toIso8601String(), 'expires_at' => $p->expires_at->toIso8601String(), 'approved_at' => $p->approved_at?->toIso8601String(), 'refunds_summary' => $totals, 'actions' => $actions,
            'update_url' => route('admin.payments.update', $p->id, absolute: false), 'show_url' => route('admin.payments.show', $p->id, absolute: false), 'action_url' => route('admin.payments.action', $p->id, absolute: false), 'subscription_url' => $p->subscription_id ? route('admin.subscriptions.show', $p->subscription_id, absolute: false) : null,
            'subscription_id' => $p->subscription_id, 'customer_url' => route('admin.users.index', ['q' => $p->user->email], absolute: false)];
        if ($full) {
            $result += ['transfer' => $p->transfer ?? ['reference' => '', 'sender' => '', 'transfer_date' => null], 'internal_note' => $p->internal_note ?? '', 'receiving' => $receiving, 'rules' => $p->receiving_snapshot['rules']];
            unset($result['receiving']['qr_path']);
            $result['receiving']['qr_url'] = filled($receiving['qr_path'] ?? null) ? route('admin.settings.payments.qr', ['method' => $p->payment_method_id, 'version' => PaymentMethodVersion::find($p->receiving_snapshot['method_version_id'])?->number], absolute: false) : null;
        }

        return $result;
    }

    public function details(PaymentTransaction $p): array
    {
        $p->load(['user', 'refunds', 'receipts']);
        $plan = null;
        $planError = null;
        if (! in_array($this->status($p), ['approved', 'rejected', 'expired', 'cancelled'], true)) {
            try {
                $plan = $this->plan($p->user, $p->purpose, $p->package_snapshot);
            } catch (ValidationException $e) {
                $planError = collect($e->errors())->flatten()->first();
            }
        }
        $receipts = $p->receipts->sortBy([['created_at', 'asc'], ['id', 'asc']])->values()->map(fn ($r) => ['id' => $r->id, 'current' => $r->is_current, 'refund_id' => $r->payment_refund_id, 'name' => $r->original_name, 'mime' => $r->mime, 'bytes' => $r->bytes, 'created_at' => $r->created_at->toIso8601String(), 'url' => route('admin.payments.receipt', ['payment' => $p->id, 'receipt' => $r->id], absolute: false)])->all();
        $warnings = [];
        if ($p->received_minor !== null && $p->received_minor !== $p->expected_minor) {
            $warnings[] = 'amount_mismatch';
        }
        if ($this->hasDuplicate($p, false)) {
            $warnings[] = 'possible_duplicate';
        }
        if ($this->hasDuplicate($p, true)) {
            $warnings[] = 'approved_duplicate';
        }
        if (PaymentMethod::find($p->payment_method_id)?->status !== 'active') {
            $warnings[] = 'method_inactive';
        }
        $package = Package::find($p->package_snapshot['id']);
        if (! $package || $package->published_version !== $p->package_snapshot['version'] || $package->status !== 'active') {
            $warnings[] = 'package_changed';
        }
        $subscription = $p->subscription_id ? CustomerPackageAssignment::find($p->subscription_id) : null;

        return ['payment' => $this->present($p, true), 'plan' => $plan, 'plan_error' => $planError, 'customer_revision' => $p->user->account_revision, 'warnings' => $warnings, 'receipts' => $receipts,
            'subscription' => $subscription ? ['id' => $subscription->id, 'status' => $subscription->status(), 'starts_at' => $subscription->starts_at->toIso8601String(), 'ends_at' => $subscription->ends_at->toIso8601String()] : null,
            'refunds' => $p->refunds->sortByDesc('id')->values()->map(fn ($r) => ['id' => $r->id, 'code' => 'RFD-'.str_pad((string) $r->id, 7, '0', STR_PAD_LEFT), 'amount_minor' => $r->amount_minor, 'status' => $r->status, 'reason' => $r->reason, 'details' => $r->details, 'access_action' => $r->access_action, 'created_at' => $r->created_at->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String()])->all(),
            'history' => PaymentEvent::with('actor:id,name')->where('payment_transaction_id', $p->id)->latest('id')->limit(100)->get()->map(fn ($e) => ['id' => $e->id, 'action' => $e->action, 'actor' => $e->actor?->name, 'metadata' => $this->eventMetadata($e), 'created_at' => $e->created_at->toIso8601String()])->all()];
    }

    private function eventMetadata(PaymentEvent $event): array
    {
        $metadata = $event->metadata;
        if (isset($metadata['evidence_snapshot'])) {
            $metadata['submission'] = json_decode(Crypt::decryptString($metadata['evidence_snapshot']), true, flags: JSON_THROW_ON_ERROR);
            unset($metadata['evidence_snapshot']);
        }

        if (isset($metadata['correction_snapshot'])) {
            $snapshot = json_decode(Crypt::decryptString($metadata['correction_snapshot']), true, flags: JSON_THROW_ON_ERROR);
            $metadata['corrections'] = [];
            foreach ($snapshot['after']['fields'] as $field => $value) {
                $before = $snapshot['before']['fields'][$field] ?? '';
                if ($before !== $value) {
                    $metadata['corrections'][] = ['field' => $field, 'before' => $before, 'after' => $value];
                }
            }
            unset($metadata['correction_snapshot']);
        }

        return $metadata;
    }

    public function recordingContext(User $user): array
    {
        $periods = CustomerPackageAssignment::standing()->where('user_id', $user->id)->get();
        $active = $periods->first(fn ($a) => $a->closed_kind === null && $a->starts_at->lessThanOrEqualTo(now()) && $a->ends_at->isFuture());
        $scheduled = $periods->contains(fn ($a) => $a->closed_kind === null && $a->starts_at->isFuture());
        $previous = $periods->contains(fn ($a) => $a->starts_at->lessThanOrEqualTo(now()) && $a->closed_kind !== 'cancelled');

        return ['active' => $active, 'scheduled' => $scheduled, 'previous' => $previous];
    }

    public function recordingPurposes(array $context, int $packageId, int $version): array
    {
        if ($context['scheduled']) {
            return [];
        }
        $active = $context['active'];
        if (! $active) {
            return [$context['previous'] ? 'renewal' : 'new'];
        }
        if ($active->package_id === $packageId) {
            return ['renewal'];
        }

        return ['change', 'renewal'];
    }

    public function plan(User $user, string $purpose, array $snapshot): array
    {
        $this->require($user->account_status === 'active', 'user_id', 'This customer must be active before granting paid access.');
        $periods = CustomerPackageAssignment::standing()->where('user_id', $user->id)->whereNull('closed_kind')->where('ends_at', '>', now())->get();
        $current = $periods->first(fn ($a) => $a->starts_at->lessThanOrEqualTo(now()));
        $future = $periods->first(fn ($a) => $a->starts_at->isFuture());
        $this->require(! $future, 'purpose', 'A scheduled subscription already exists. Resolve it in Subscriptions before adding another period.');
        $this->require($purpose !== 'new' || ! $current, 'purpose', 'This customer already has access. Choose Renewal or Package Change.');
        $this->require($purpose !== 'change' || (bool) $current, 'purpose', 'Package Change requires a currently active subscription.');
        $start = $purpose === 'renewal' && $current ? $current->ends_at : CarbonImmutable::now();

        return ['starts_at' => $start->toIso8601String(), 'ends_at' => $start->addDays((int) $snapshot['definition']['validity_days'])->toIso8601String(), 'replaces_id' => $purpose === 'change' ? $current?->id : null, 'current_name' => $current?->effectiveSnapshot()['name'] ?? null, 'customer_revision' => $user->account_revision];
    }

    private function grant(User $actor, PaymentTransaction $p, User $user, array $plan): CustomerPackageAssignment
    {
        $start = CarbonImmutable::parse($plan['starts_at']);
        $old = $plan['replaces_id'] ? CustomerPackageAssignment::lockForUpdate()->findOrFail($plan['replaces_id']) : null;
        if ($old) {
            $old->update(['closed_kind' => 'replaced', 'closed_at' => now()]);
        }
        $assignment = CustomerPackageAssignment::create(['user_id' => $user->id, 'package_id' => $p->package_snapshot['id'], 'package_version_id' => $p->package_version_id, 'assigned_by' => $actor->id,
            'snapshot' => array_diff_key($p->package_snapshot, ['id' => true]), 'starts_at' => $start, 'ends_at' => CarbonImmutable::parse($plan['ends_at']), 'source' => 'payment_'.$p->purpose,
            'allowance_starts_at' => $old ? ($old->allowance_starts_at ?? $old->starts_at) : $start]);
        $user->increment('account_revision');
        CustomerAccountEvent::create(['user_id' => $user->id, 'actor_id' => $actor->id, 'type' => 'subscription_payment', 'metadata' => ['payment_id' => $p->id, 'assignment_id' => $assignment->id, 'purpose' => $p->purpose, 'before_id' => $old?->id, 'starts_at' => $plan['starts_at'], 'ends_at' => $plan['ends_at']], 'operation_key' => (string) Str::uuid(), 'fingerprint' => hash('sha256', 'payment:'.$p->id)]);

        return $assignment;
    }

    private function referenceHash(PaymentTransaction $p, string $reference): ?string
    {
        $reference = mb_strtolower(preg_replace('/\s+/u', '', trim($reference)));

        return $reference === '' ? null : hash_hmac('sha256', $p->currency.'|'.mb_strtolower(trim($p->receiving_snapshot['receiving']['provider'])).'|'.$reference, (string) config('app.key'));
    }

    private function hasDuplicate(PaymentTransaction $p, bool $approved): bool
    {
        $query = PaymentTransaction::whereKeyNot($p->id);
        if ($approved) {
            $query->where('status', 'approved');
        } else {
            $query->whereNotIn('status', ['rejected', 'cancelled']);
        }
        $hashes = $p->receipts()->whereNull('payment_refund_id')->where('is_current', true)->pluck('sha256');
        if (! $p->reference_hash && $hashes->isEmpty()) {
            return false;
        }

        return $query->where(function ($q) use ($p, $hashes): void {
            if ($p->reference_hash) {
                $q->where('reference_hash', $p->reference_hash);
            }if ($hashes->isNotEmpty()) {
                $q->orWhereHas('receipts', fn ($r) => $r->whereNull('payment_refund_id')->where('is_current', true)->whereIn('sha256', $hashes));
            }
        })->exists();
    }

    public function create(User $actor, array $data): array
    {
        return $this->execute($actor, null, $data, function (?PaymentTransaction $unused, User $user, array $data, ?UploadedFile $file, array &$paths) use ($actor): array {
            $p = new PaymentTransaction;
            $this->configureRecord($p, $user, $data);
            $p->created_by = $actor->id;
            $p->status = 'awaiting_payment';
            if ($data['entry'] === 'received') {
                $this->evidence($p, $data, $file);
                $p->status = 'pending_review';
                $p->submitted_at = now();
            }
            $p->save();
            if ($file) {
                $this->storeReceipt($actor, $p, $file, $paths);
            }

            return ['payment' => $p, 'metadata' => ['status' => $p->status, 'entry' => $data['entry']]];
        });
    }

    public function submitCustomer(User $actor, array $data, ?int $id = null): array
    {
        if ($id !== null) {
            PaymentTransaction::where('user_id', $actor->id)->findOrFail($id);
        }
        $data['user_id'] = $actor->id;
        $data['action'] = $id === null ? 'created' : 'edit';

        return $this->execute($actor, $id, $data, function (?PaymentTransaction $payment, User $user, array $data, ?UploadedFile $file, array &$paths) use ($actor): array {
            $payment ??= new PaymentTransaction;
            abort_unless(! $payment->exists || $payment->user_id === $actor->id, 404);
            $this->require(! $payment->exists || (in_array($this->status($payment), ['awaiting_payment', 'pending_review', 'needs_information'], true) && $payment->subscription_id === null), 'payment', 'This payment is being reviewed or has already been resolved. Refresh its details.');
            $this->require(! PaymentTransaction::where('user_id', $actor->id)->when($payment->exists, fn ($q) => $q->whereKeyNot($payment->id))->where(function ($q): void {
                $q->whereIn('status', ['pending_review', 'under_review', 'needs_information'])->orWhere(fn ($r) => $r->where('status', 'awaiting_payment')->where('expires_at', '>', now()));
            })->exists(), 'payment', 'You already have a payment awaiting action. Open its details instead.');
            $existing = $payment->exists;
            $before = $existing ? $this->status($payment) : null;
            $context = $this->recordingContext($user);
            $purposes = $this->recordingPurposes($context, (int) $data['package_id'], (int) $data['package_version']);
            $this->require(count($purposes) > 0, 'package_id', 'A next subscription is already scheduled. Contact support before making another payment.');
            $data['purpose'] = $existing ? $payment->purpose : $purposes[0];
            $data['internal_note'] = $payment->internal_note ?? '';
            $oldCurrency = $payment->currency;
            $this->configureRecord($payment, $user, $data);
            $this->require(! $existing || $oldCurrency === $payment->currency || $file !== null, 'receipt', 'Upload a receipt in the selected currency.');
            $this->require($file !== null || ($existing && $payment->receipts()->whereNull('payment_refund_id')->where('is_current', true)->exists()), 'receipt', 'Upload your transfer receipt.');
            $this->evidence($payment, $data, $file);
            $payment->status = 'pending_review';
            $payment->submitted_at = now();
            $payment->reviewed_by = null;
            if ($existing) {
                $payment->revision++;
            } else {
                $payment->created_by = $actor->id;
            }
            $payment->save();
            if ($file !== null) {
                $this->storeReceipt($actor, $payment, $file, $paths);
            }

            return ['payment' => $payment, 'metadata' => ['before' => $before, 'status' => 'pending_review', 'entry' => 'received', 'source' => 'customer', 'reason' => $existing ? 'Customer updated transfer information.' : 'Customer submitted payment.']];
        });
    }

    private function configureRecord(PaymentTransaction $p, User $user, array $data): void
    {
        $this->require(! $user->is_admin && $user->account_status === 'active', 'user_id', 'Choose an active customer account.');
        $retainingPackage = $p->exists && (int) $p->package_snapshot['id'] === (int) $data['package_id'] && (int) $p->package_snapshot['version'] === (int) $data['package_version'];
        if ($retainingPackage) {
            $snapshot = $p->package_snapshot;
            $versionId = $p->package_version_id;
        } else {
            $package = Package::lockForUpdate()->findOrFail($data['package_id']);
            $this->require($package->status === 'active' && $package->published_version > 0, 'package_id', 'Choose an active published package.');
            abort_unless($package->published_version === (int) $data['package_version'], 409, 'Package changed. Refresh package options.');
            $version = PackageVersion::where('package_id', $package->id)->where('number', $package->published_version)->firstOrFail();
            $this->require(($version->definition['type'] ?? $package->type) === 'paid' && (int) $version->definition['price_minor'] > 0, 'package_id', 'Free Trial assignments do not need a paid transaction.');
            $snapshot = ['id' => $package->id, 'name' => $package->name, 'code' => $package->code, 'type' => 'paid', 'version' => $version->number, 'definition' => $version->definition, 'validity_mode' => 'package'];
            $versionId = $version->id;
        }
        $definition = $snapshot['definition'];
        $context = $this->recordingContext($user);
        $this->require(! $context['scheduled'], 'purpose', 'A scheduled subscription already exists. Resolve it in Subscriptions before adding another period.');
        $this->require(in_array($data['purpose'], $this->recordingPurposes($context, (int) $snapshot['id'], (int) $snapshot['version']), true), 'purpose', 'This purpose does not match the selected customer and package. Refresh payment options.');
        $this->plan($user, $data['purpose'], $snapshot);
        abort_unless(app(PaymentConfiguration::class)->settings()['revision'] === (int) $data['config_revision'], 409, 'Receiving accounts changed. Refresh payment options.');
        $receiving = $p->exists && $p->payment_method_id === (int) $data['payment_method_id'] && $p->currency === $definition['currency']
            ? $p->receiving_snapshot
            : app(PaymentConfiguration::class)->capture($definition['currency'], (int) $data['payment_method_id']);
        $p->forceFill(['user_id' => $user->id, 'package_version_id' => $versionId, 'payment_method_id' => $data['payment_method_id'], 'package_snapshot' => $snapshot, 'receiving_snapshot' => $receiving,
            'currency' => $definition['currency'], 'expected_minor' => (int) $definition['price_minor'], 'purpose' => $data['purpose'], 'internal_note' => $data['internal_note'] ?? '']);
        if (! $p->exists) {
            $p->expires_at = now()->addHours($receiving['rules']['expiry_hours']);
        }
    }

    private function correctionSnapshot(PaymentTransaction $p): array
    {
        $currency = $p->currency;
        $money = fn ($minor) => $minor === null ? '' : $currency.' '.number_format($minor / 100, 2, '.', '');

        return ['package_snapshot' => $p->package_snapshot, 'receiving_snapshot' => $p->receiving_snapshot,
            'fields' => ['package' => $p->package_snapshot['name'].' (v'.$p->package_snapshot['version'].')', 'purpose' => $p->purpose,
                'method' => $p->receiving_snapshot['receiving']['name'].' / '.$currency, 'expected' => $money($p->expected_minor), 'received' => $money($p->received_minor),
                'reference' => $p->transfer['reference'] ?? '', 'sender' => $p->transfer['sender'] ?? '', 'transferDate' => $p->transfer['transfer_date'] ?? '', 'note' => $p->internal_note ?? '']];
    }

    public function update(User $actor, int $id, array $data): array
    {
        $data['action'] = 'edit';

        return $this->execute($actor, $id, $data, function (PaymentTransaction $p, User $user, array $data, ?UploadedFile $file, array &$paths) use ($actor): array {
            $status = $this->status($p);
            $this->require(in_array($status, ['awaiting_payment', 'pending_review', 'under_review', 'needs_information'], true) && $p->subscription_id === null, 'action', 'Only unapproved payments can be edited.');
            $this->require((int) $data['user_id'] === $p->user_id, 'user_id', 'The customer cannot be changed on an existing payment.');
            $this->require(filled(trim($data['reason'] ?? '')), 'reason', 'Enter the reason for this correction.');
            $this->require($p->received_minor === null || $data['entry'] === 'received', 'entry', 'A received transfer cannot be changed into an unpaid request.');
            $before = $this->correctionSnapshot($p);
            $oldCurrency = $p->currency;
            $this->configureRecord($p, $user, $data);
            if ($data['entry'] === 'received') {
                $this->require($oldCurrency === $p->currency || $file !== null, 'receipt', 'Upload a receipt in the corrected currency.');
                $this->evidence($p, $data, $file);
                if ($file) {
                    $this->storeReceipt($actor, $p, $file, $paths);
                }
                $p->status = 'pending_review';
                $p->submitted_at ??= now();
            } else {
                $p->status = 'awaiting_payment';
            }
            $p->reviewed_by = null;
            $p->revision++;
            $p->save();

            return ['payment' => $p, 'metadata' => ['before' => $status, 'status' => $p->status, 'reason' => $data['reason'],
                'correction_snapshot' => Crypt::encryptString(json_encode(['before' => $before, 'after' => $this->correctionSnapshot($p)], JSON_THROW_ON_ERROR))]];
        });
    }

    public function act(User $actor, int $id, array $data): array
    {
        return $this->execute($actor, $id, $data, function (PaymentTransaction $p, User $user, array $data, ?UploadedFile $file, array &$paths) use ($actor): array {
            $action = $data['action'];
            $status = $this->status($p);
            $meta = ['before' => $status, 'reason' => $data['reason'] ?? ''];
            if ($action === 'evidence') {
                $this->require(in_array($status, ['awaiting_payment', 'needs_information'], true), 'action', 'Evidence cannot be changed in this state.');
                $this->evidence($p, $data, $file);
                if ($file) {
                    $this->storeReceipt($actor, $p, $file, $paths);
                }
                $p->status = 'pending_review';
                $p->submitted_at = now();
            } elseif (in_array($action, ['review', 'approve', 'information', 'reject', 'cancel'], true)) {
                $allowed = match ($action) {
                    'cancel' => in_array($status, ['awaiting_payment', 'needs_information'], true),'review' => $status === 'pending_review',default => in_array($status, ['pending_review', 'under_review'], true)
                };
                $this->require($allowed, 'action', 'This action is no longer available.');
                if ($action === 'approve') {
                    foreach (['account', 'amount', 'reference', 'outcome'] as $key) {
                        $this->require((bool) ($data['verified'][$key] ?? false), 'verified', 'Confirm every verification check before approval.');
                    }
                    abort_unless($user->account_revision === (int) ($data['customer_revision'] ?? 0), 409, 'Customer subscription changed. Open Review again.');
                    $this->validateEvidence($p);
                    $this->require($p->received_minor === $p->expected_minor, 'received', 'Received amount must exactly match the requested amount and currency.');
                    $this->require(! $this->hasDuplicate($p, true), 'reference', 'This transfer reference or receipt is already attached to an approved payment.');
                    $plan = $this->plan($user, $p->purpose, $p->package_snapshot);
                    $assignment = $this->grant($actor, $p, $user, $plan);
                    $p->subscription_id = $assignment->id;
                    $p->status = 'approved';
                    $p->approved_at = now();
                    $p->reviewed_by = $actor->id;
                    $p->approved_reference_hash = $p->reference_hash;
                    $meta += ['subscription_id' => $assignment->id, 'starts_at' => $plan['starts_at'], 'ends_at' => $plan['ends_at']];
                } else {
                    $p->status = match ($action) {
                        'review' => 'under_review','information' => 'needs_information','reject' => 'rejected','cancel' => 'cancelled'
                    };
                    $p->reviewed_by = $actor->id;
                }
            } else {
                $this->require($status === 'approved', 'action', 'Refunds require an approved payment.');
                $p->load('refunds');
                $meta += $this->refund($actor, $p, $user, $data, $file, $paths);
            }
            $p->revision++;
            $p->save();
            if ($action === 'information') {
                $meta['support_id'] = app(SupportInbox::class)->paymentInformation($actor, $p, $data['reason'], $data['request_id']);
            }

            return ['payment' => $p, 'metadata' => [...$meta, 'status' => $p->status]];
        });
    }

    private function evidence(PaymentTransaction $p, array $data, ?UploadedFile $file): void
    {
        $p->received_minor = $this->money($data['received'] ?? null);
        $p->transfer = ['reference' => trim($data['reference'] ?? ''), 'sender' => trim($data['sender'] ?? ''), 'transfer_date' => $data['transfer_date'] ?? null];
        $p->reference_hash = $this->referenceHash($p, $p->transfer['reference']);
        $p->reference_lookup = filled($p->transfer['reference']) ? hash_hmac('sha256', mb_strtolower(preg_replace('/\\s+/u', '', trim($p->transfer['reference']))), (string) config('app.key')) : null;
        $this->validateEvidence($p, $file);
    }

    private function validateEvidence(PaymentTransaction $p, ?UploadedFile $file = null): void
    {
        $rules = $p->receiving_snapshot['rules'];
        $transfer = $p->transfer ?? [];
        foreach (['reference', 'sender', 'transfer_date'] as $key) {
            $this->require(! ($rules[$key.'_required'] ?? false) || filled($transfer[$key] ?? null), $key, 'This payment requires '.$key.'.');
        }
        $this->require($p->received_minor !== null && $p->received_minor > 0, 'received', 'Enter the received amount.');
        $this->require(! $rules['evidence_required'] || $file || ($p->exists && $p->receipts()->whereNull('payment_refund_id')->where('is_current', true)->exists()), 'receipt', 'A transfer receipt is required.');
    }

    private function refund(User $actor, PaymentTransaction $p, User $user, array $data, ?UploadedFile $file, array &$paths): array
    {
        $action = $data['action'];
        $refund = null;
        if ($action === 'refund') {
            $amount = $this->money($data['amount'] ?? null, 'amount');
            $totals = $this->totals($p);
            $this->require($amount > 0 && $amount <= $totals['remaining'], 'amount', 'Refund amount exceeds the remaining refundable amount.');
            $this->require(filled($data['destination'] ?? null), 'destination', 'Describe the verified refund destination.');
            $refund = PaymentRefund::create(['payment_transaction_id' => $p->id, 'amount_minor' => $amount, 'status' => 'requested', 'access_action' => $data['access_action'] ?? 'keep',
                'reason' => $data['reason'], 'details' => ['destination' => $data['destination'], 'reference' => '', 'transfer_date' => null], 'created_by' => $actor->id]);
        } else {
            $refund = PaymentRefund::where('payment_transaction_id', $p->id)->lockForUpdate()->findOrFail($data['refund_id']);
            $this->require(in_array($refund->status, ['requested', 'under_review'], true), 'refund_id', 'This refund has already been resolved.');
            if ($action === 'refund_complete') {
                $this->require((bool) ($data['confirmed'] ?? false), 'confirmed', 'Confirm that the refund was transferred externally.');
                $this->require(filled($data['reference'] ?? null) && filled($data['transfer_date'] ?? null), 'reference', 'Provide the actual refund reference and transfer date.');

                $reference = mb_strtolower(preg_replace('/\s+/u', '', trim($data['reference'] ?? '')));
                $this->require(! $p->refunds->contains(fn ($previous) => $previous->status === 'completed' && mb_strtolower(preg_replace('/\s+/u', '', trim($previous->details['reference'] ?? ''))) === $reference), 'reference', 'This outgoing refund reference was already recorded for this payment.');
                $this->require(! $p->receiving_snapshot['rules']['evidence_required'] || $file, 'receipt', 'Provide refund transfer proof.');
                $access = $data['access_action'] ?? $refund->access_action;
                $assignment = $p->subscription_id ? CustomerPackageAssignment::lockForUpdate()->find($p->subscription_id) : null;
                if ($access !== 'keep') {
                    $this->require($assignment && (in_array($access, ['end', 'cancel'], true)) && $assignment->status() === ($access === 'end' ? 'active' : 'upcoming'), 'access_action', 'Only the linked active or upcoming subscription can be ended or cancelled. Review its current state.');
                    $assignment->update(['closed_kind' => $access === 'end' ? 'ended' : 'cancelled', 'closed_at' => now()]);
                    $user->increment('account_revision');
                    CustomerAccountEvent::create(['user_id' => $user->id, 'actor_id' => $actor->id, 'type' => 'subscription_refund', 'metadata' => ['payment_id' => $p->id, 'refund_id' => $refund->id, 'assignment_id' => $assignment->id, 'reason' => $data['reason'], 'access_action' => $access], 'operation_key' => (string) Str::uuid(), 'fingerprint' => hash('sha256', 'refund:'.$refund->id)]);
                }
                $refund->details = [...$refund->details, 'reference' => trim($data['reference']), 'transfer_date' => $data['transfer_date']];
                $refund->access_action = $access;
                $refund->status = 'completed';
                $refund->completed_at = now();
                if ($file) {
                    $this->storeReceipt($actor, $p, $file, $paths, $refund->id);
                }
            } else {
                $refund->status = match ($action) {
                    'refund_review' => 'under_review','refund_reject' => 'rejected','refund_cancel' => 'cancelled',default => throw ValidationException::withMessages(['action' => 'Invalid refund action.'])
                };
            }
            $refund->save();
        }

        return ['refund_id' => $refund->id, 'refund_status' => $refund->status, 'amount_minor' => $refund->amount_minor, 'access_action' => $refund->access_action, 'reason' => $data['reason']];
    }

    private function storeReceipt(User $actor, PaymentTransaction $p, UploadedFile $file, array &$paths, ?int $refundId = null): void
    {
        $id = (string) Str::uuid();
        $path = 'payment-receipts/'.$id.'.'.$file->extension();
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            abort_unless(Storage::disk('local')->put($path, $stream), 503, 'Unable to store receipt.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $paths[] = $path;
        if ($refundId === null) {
            $p->receipts()->whereNull('payment_refund_id')->where('is_current', true)->update(['is_current' => false]);
        }
        PaymentReceipt::create(['id' => $id, 'payment_transaction_id' => $p->id, 'payment_refund_id' => $refundId, 'is_current' => $refundId === null, 'path' => $path, 'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 150), 'uploaded_by' => $actor->id]);
    }

    private function execute(User $actor, ?int $id, array $data, \Closure $callback): array
    {
        $file = $data['receipt'] ?? null;
        unset($data['receipt']);
        $data['receipt_digest'] = $file ? hash_file('sha256', $file->getRealPath()) : null;
        $fingerprint = hash_hmac('sha256', json_encode([$actor->id, $id, $data], JSON_THROW_ON_ERROR), (string) config('app.key'));
        $paths = [];
        $userId = $id ? PaymentTransaction::findOrFail($id)->user_id : (int) $data['user_id'];
        $transaction = function () use ($actor, $id, $data, $file, $callback, $fingerprint, $userId, &$paths): array {
            return DB::transaction(function () use ($actor, $id, $data, $file, $callback, $fingerprint, $userId, &$paths): array {
                DB::table('payment_settings')->where('id', 1)->lockForUpdate()->first();
                $prior = PaymentEvent::where('operation_key', $data['request_id'])->first();
                if ($prior) {
                    abort_unless(hash_equals($prior->fingerprint, $fingerprint), 409, 'This request ID has already been used.');

                    return ['id' => $prior->payment_transaction_id, 'replayed' => true];
                }
                $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
                $p = $id ? PaymentTransaction::lockForUpdate()->findOrFail($id) : null;
                if ($p) {
                    abort_unless($p->revision === (int) $data['revision'], 409, 'This payment changed. Refresh its details before saving.');
                }
                $result = $callback($p, $user, $data, $file, $paths);
                $payment = $result['payment'];
                if (in_array($data['action'] ?? null, ['evidence', 'edit'], true) && $payment->submitted_at || (! $id && $payment->submitted_at)) {
                    $result['metadata']['evidence_snapshot'] = Crypt::encryptString(json_encode(['received_minor' => $payment->received_minor, ...$payment->transfer], JSON_THROW_ON_ERROR));
                }
                PaymentEvent::create(['payment_transaction_id' => $payment->id, 'actor_id' => $actor->id, 'action' => $data['action'] ?? 'created', 'metadata' => $result['metadata'], 'operation_key' => $data['request_id'], 'fingerprint' => $fingerprint]);

                return ['id' => $payment->id, 'replayed' => false];
            });
        };
        try {
            return Cache::lock('payments:ledger', 30)->block(10, fn () => Cache::lock('customer:'.$userId, 30)->block(10, $transaction));
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }throw $error;
        }
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
