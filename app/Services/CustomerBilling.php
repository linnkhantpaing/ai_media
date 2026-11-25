<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CustomerBilling
{
    public function __construct(private Payments $payments, private PaymentConfiguration $configuration) {}

    public function query(User $user, array $filters): Builder
    {
        return PaymentTransaction::where('user_id', $user->id)->with('refunds')
            ->when($filters['q'] ?? '', function ($q, $term): void {
                $q->where(function ($query) use ($term): void {
                    $query->where('id', preg_replace('/[^0-9]/', '', $term) ?: 0)
                        ->orWhere('reference_lookup', hash_hmac('sha256', mb_strtolower(preg_replace('/\s+/u', '', trim($term))), (string) config('app.key')));
                });
            })
            ->when($filters['currency'] ?? '', fn ($q, $value) => $q->where('currency', $value))
            ->when($filters['status'] ?? '', function ($q, $value): void {
                if ($value === 'expired') {
                    $q->where(fn ($r) => $r->where('status', 'expired')->orWhere(fn ($s) => $s->where('status', 'awaiting_payment')->where('expires_at', '<=', now())));
                } else {
                    $q->where('status', $value);
                    if ($value === 'awaiting_payment') {
                        $q->where('expires_at', '>', now());
                    }
                }
            })
            ->when(($filters['tab'] ?? '') === 'review', fn ($q) => $q->where(fn ($r) => $r->whereIn('status', ['pending_review', 'under_review', 'needs_information'])->orWhere(fn ($s) => $s->where('status', 'awaiting_payment')->where('expires_at', '>', now()))))
            ->when(($filters['tab'] ?? '') === 'approved', fn ($q) => $q->where('status', 'approved'))
            ->when(($filters['tab'] ?? '') === 'closed', fn ($q) => $q->where(fn ($r) => $r->whereIn('status', ['rejected', 'cancelled', 'expired'])->orWhere(fn ($s) => $s->where('status', 'awaiting_payment')->where('expires_at', '<=', now()))))
            ->when(($filters['tab'] ?? '') === 'refunds', fn ($q) => $q->whereHas('refunds'))
            ->when(($filters['period'] ?? '30') !== 'all', fn ($q) => $q->where('created_at', '>=', now()->subDays((int) ($filters['period'] ?? 30))))
            ->orderBy('id', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');
    }

    public function counts(User $user): array
    {
        $q = PaymentTransaction::where('user_id', $user->id);

        return ['all' => (clone $q)->count(),
            'review' => (clone $q)->where(fn ($r) => $r->whereIn('status', ['pending_review', 'under_review', 'needs_information'])->orWhere(fn ($s) => $s->where('status', 'awaiting_payment')->where('expires_at', '>', now())))->count(),
            'approved' => (clone $q)->where('status', 'approved')->count(),
            'closed' => (clone $q)->where(fn ($r) => $r->whereIn('status', ['rejected', 'cancelled', 'expired'])->orWhere(fn ($s) => $s->where('status', 'awaiting_payment')->where('expires_at', '<=', now())))->count(),
            'refunds' => (clone $q)->whereHas('refunds')->count()];
    }

    public function pending(User $user): ?PaymentTransaction
    {
        return PaymentTransaction::where('user_id', $user->id)->where(fn ($q) => $q->whereIn('status', ['pending_review', 'under_review', 'needs_information'])->orWhere(fn ($r) => $r->where('status', 'awaiting_payment')->where('expires_at', '>', now())))->latest('id')->first();
    }

    public function present(PaymentTransaction $payment, bool $full = false): array
    {
        $payment->loadMissing('refunds');
        $receiving = $payment->receiving_snapshot['receiving'];
        $snapshot = $payment->package_snapshot;
        $status = $this->payments->status($payment);
        $result = ['id' => $payment->id, 'code' => 'PYT-'.str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT),
            'revision' => $payment->revision, 'status' => $status, 'purpose' => $payment->purpose,
            'package' => ['id' => $snapshot['id'], 'name' => $snapshot['name'], 'version' => $snapshot['version'], 'days' => $snapshot['definition']['validity_days'] ?? null,
                'topup' => ($snapshot['type'] ?? null) === 'topup' ? ['workflow' => $snapshot['definition']['topup']['workflow'] ?? null, 'minutes' => (int) ($snapshot['definition']['topup']['minutes'] ?? 0)] : null],
            'currency' => $payment->currency, 'expected_minor' => $payment->expected_minor, 'received_minor' => $payment->received_minor,
            'method' => ['id' => $payment->payment_method_id, 'name' => $receiving['name'], 'type' => $receiving['type'], 'provider' => $receiving['provider'], 'masked_identifier' => '•••• '.mb_substr($receiving['details']['identifier'] ?? '', -4)],
            'created_at' => $payment->created_at->toIso8601String(), 'submitted_at' => $payment->submitted_at?->toIso8601String(), 'approved_at' => $payment->approved_at?->toIso8601String(),
            'editable' => in_array($status, ['awaiting_payment', 'pending_review', 'needs_information'], true) && $payment->subscription_id === null,
            'refunds' => $payment->refunds->map(fn ($r) => ['id' => $r->id, 'amount_minor' => $r->amount_minor, 'status' => $r->status, 'created_at' => $r->created_at->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String()])->values()->all()];
        if ($full) {
            $events = PaymentEvent::where('payment_transaction_id', $payment->id)->orderBy('id')->get();
            $receipts = $payment->receipts()->whereNull('payment_refund_id')->where('is_current', true)->get();
            $result += ['receiving' => $this->receiving($receiving, route('account.billing.payment-qr', $payment->id, absolute: false)),
                'transfer' => array_intersect_key($payment->transfer ?? [], array_flip(['reference', 'sender', 'transfer_date'])),
                'rules' => array_intersect_key($payment->receiving_snapshot['rules'], array_flip(['reference_required', 'sender_required', 'transfer_date_required'])),
                'receipts' => $receipts->map(fn ($r) => ['id' => $r->id, 'name' => $r->original_name, 'mime' => $r->mime, 'bytes' => $r->bytes, 'url' => route('account.billing.receipt', ['payment' => $payment->id, 'receipt' => $r->id], absolute: false)])->all(),
                'request' => in_array($status, ['needs_information', 'rejected'], true) ? ($events->where('action', $status === 'needs_information' ? 'information' : 'reject')->last()?->metadata['reason'] ?? null) : null,
                'history' => $events->filter(fn ($e) => in_array($e->action, ['created', 'edit', 'evidence', 'review', 'approve', 'information', 'reject', 'cancel', 'refund', 'refund_complete', 'refund_cancel'], true))
                    ->map(fn ($e) => ['action' => $e->action, 'at' => $e->created_at->toIso8601String()])->values()->all()];
        }

        return $result;
    }

    private function receiving(array $value, string $qr): array
    {
        return ['name' => $value['name'], 'provider' => $value['provider'], 'type' => $value['type'],
            'details' => array_intersect_key($value['details'], array_flip(['holder', 'identifier', 'branch', 'routing'])),
            'instructions' => $value['instructions'] ?? [], 'qr_url' => filled($value['qr_path'] ?? null) ? $qr : null];
    }

    public function options(User $user): array
    {
        $settings = $this->configuration->settings();
        $context = $this->payments->recordingContext($user);
        $methods = PaymentMethod::where('status', 'active')->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn ($method) => ($settings['currencies'][$method->currency]['enabled'] ?? false) && $this->configuration->ready($method))
            ->map(fn ($method) => ['id' => $method->id, 'currency' => $method->currency, 'default' => $settings['currencies'][$method->currency]['default_id'] === $method->id,
                ...$this->receiving(['name' => $method->name, 'provider' => $method->provider, 'type' => $method->type, 'details' => $method->details, 'instructions' => $method->instructions, 'qr_path' => $method->qr_path], route('account.billing.method-qr', $method->id, absolute: false))])->values();
        $packages = Package::where('status', 'active')->where('published_version', '>', 0)->orderBy('id')->get();
        $topups = $packages->map(function ($package) use ($context, $methods): ?array {
            $definition = PackageVersion::where('package_id', $package->id)->where('number', $package->published_version)->value('definition') ?? [];
            if (($definition['type'] ?? $package->type) !== 'topup' || ($definition['price_minor'] ?? 0) <= 0 || ! ($definition['topup']['workflow'] ?? null)) {
                return null;
            }
            $blocker = $this->payments->topupBlocker($context['active'], $package->id, $definition);

            return ['id' => $package->id, 'name' => $definition['name'] ?? $package->name, 'version' => $package->published_version, 'kind' => 'topup',
                'currency' => $definition['currency'], 'price_minor' => (int) $definition['price_minor'], 'days' => null,
                'seconds' => (int) $definition['topup']['minutes'] * 60, 'storage' => 0, 'videos' => null, 'workflow' => $definition['topup']['workflow'],
                'purpose' => $blocker === null ? 'topup' : null, 'period' => $blocker === null ? ['starts_at' => now()->toIso8601String(), 'ends_at' => $context['active']->ends_at->toIso8601String()] : null,
                'available' => $blocker === null && $methods->contains('currency', $definition['currency']),
                'reason' => $blocker === null ? null : ($context['active'] && $context['active']->status() === 'active' ? 'topupNeedsWorkflow' : 'topupNeedsPlan')];
        });
        $plans = $packages->map(function ($package) use ($context, $methods, $user): ?array {
            $version = PackageVersion::where('package_id', $package->id)->where('number', $package->published_version)->first();
            $definition = $version?->definition ?? [];
            if (($definition['type'] ?? $package->type) !== 'paid' || ($definition['price_minor'] ?? 0) <= 0) {
                return null;
            }
            $purpose = $this->payments->recordingPurposes($context, $package->id, $version->number)[0] ?? null;
            $plan = $purpose ? $this->payments->plan($user, $purpose, ['definition' => $definition]) : null;

            return ['id' => $package->id, 'name' => $definition['name'] ?? $package->name, 'version' => $version->number, 'kind' => 'plan',
                'currency' => $definition['currency'], 'price_minor' => (int) $definition['price_minor'], 'days' => $definition['validity_days'],
                'seconds' => $definition['included_seconds'] ?? (($definition['included_minutes'] ?? 0) * 60),
                'storage' => $definition['storage_bytes'] ?? 0, 'videos' => $definition['video_limit'] ?? null,
                'purpose' => $purpose, 'period' => $plan ? array_intersect_key($plan, array_flip(['starts_at', 'ends_at'])) : null,
                'available' => $purpose !== null && $methods->contains('currency', $definition['currency'])];
        });

        return ['revision' => $settings['revision'], 'rules' => array_intersect_key($settings['rules'], array_flip(['reference_required', 'sender_required', 'transfer_date_required', 'instructions'])),
            'plans' => $plans->filter()->values()->all(), 'topups' => $topups->filter()->values()->all(), 'methods' => $methods->all(),
            'pending' => ($pending = $this->pending($user)) ? $this->present($pending) : null,
            'scheduled' => $context['scheduled']];
    }
}
