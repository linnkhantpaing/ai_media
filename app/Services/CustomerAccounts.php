<?php

namespace App\Services;

use App\Models\CustomerAccountEvent;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Notifications\CustomerWelcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerAccounts
{
    public function canProcess(int $userId): bool
    {
        return User::whereKey($userId)->where('account_status', 'active')->exists();
    }

    public function customer(int $id, bool $lock = false): User
    {
        $query = User::where('is_admin', false);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }

    public function packageOptions(): array
    {
        return Package::where('status', 'active')->where('published_version', '>', 0)->orderBy('name')->get()
            ->map(function (Package $package): ?array {
                $version = PackageVersion::where('package_id', $package->id)->where('number', $package->published_version)->first();
                if (! $version) {
                    return null;
                }

                return ['id' => $package->id, 'name' => $package->name, 'code' => $package->code, 'type' => $version->definition['type'] ?? $package->type, 'version' => $version->number, 'validity_days' => $version->definition['validity_days'], 'storage_gib' => $version->definition['storage_gib'], 'included_minutes' => $version->definition['included_minutes'], 'video_limit' => $version->definition['video_limit'] ?? null, 'concurrency' => $version->definition['concurrency'], 'max_video_seconds' => $version->definition['max_video_seconds'], 'max_upload_mib' => $version->definition['max_upload_mib'], 'retention_days' => $version->definition['retention_days'], 'features' => array_intersect_key($version->definition['features'] ?? [], array_flip(PackageCatalog::FEATURES))];
            })->filter()->values()->all();
    }

    public function resource(User $user): array
    {
        $assignment = $user->currentPackageAssignment;
        $snapshot = $assignment?->effectiveSnapshot();
        $package = $assignment ? [
            'id' => $assignment->id, 'package_id' => $assignment->package_id,
            'name' => $snapshot['name'], 'code' => $snapshot['code'],
            'version' => $snapshot['version'],
            'type' => $snapshot['type'] ?? $snapshot['definition']['type'] ?? 'paid',
            'validity_days' => $snapshot['definition']['validity_days'],
            'storage_gib' => $snapshot['definition']['storage_gib'],
            'included_minutes' => $snapshot['definition']['included_minutes'],
            'video_limit' => $snapshot['definition']['video_limit'] ?? null,
            'concurrency' => $snapshot['definition']['concurrency'],
            'max_video_seconds' => $snapshot['definition']['max_video_seconds'],
            'max_upload_mib' => $snapshot['definition']['max_upload_mib'],
            'retention_days' => $snapshot['definition']['retention_days'],
            'features' => array_intersect_key($snapshot['definition']['features'] ?? [], array_flip(PackageCatalog::FEATURES)),
            'period_days' => (int) $assignment->starts_at->startOfDay()->diffInDays($assignment->ends_at->startOfDay()),
            'validity_mode' => $assignment->validityMode($snapshot),
            'starts_at' => $assignment->starts_at->toIso8601String(),
            'ends_at' => $assignment->ends_at->toIso8601String(),
            'expired' => $assignment->ends_at->lessThanOrEqualTo(now()),
            'days_left' => max(0, (int) ceil(now()->diffInDays($assignment->ends_at, false))),
            'scheduled' => $assignment->starts_at->greaterThan(now()),
            'ended' => $assignment->closed_kind === 'ended',
        ] : null;

        $displayStatus = $user->account_status !== 'active' ? $user->account_status : ($package && ($package['expired'] || $package['ended']) ? 'expired' : ($package && $package['scheduled'] ? 'scheduled' : 'active'));

        return ['display_status' => $displayStatus, 'package' => $package, 'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->account_status, 'preferred_locale' => $user->preferred_locale ?? 'en', 'note' => $user->account_note ?? '', 'revision' => $user->account_revision, 'created_at' => $user->created_at?->toIso8601String(), 'updated_at' => $user->updated_at?->toIso8601String(), 'last_login_at' => $user->last_login_at?->toIso8601String(), 'managed' => (bool) $user->managed_account];
    }

    public function mailReady(): bool
    {
        $smtp = app(GeneralSettings::class)->current()['configuration']['smtp'];

        return $smtp['enabled'] && filled($smtp['host']) && filled($smtp['from_email'])
            && (! filled($smtp['username']) || filled($smtp['password']));
    }

    public function create(User $actor, array $data): array
    {
        $fingerprint = $this->fingerprint($actor, $data);

        return Cache::lock('customer-create:'.$data['request_id'], 60)->block(5, function () use ($actor, $data, $fingerprint): array {
            $existing = User::where('account_creation_key', $data['request_id'])->first();
            if ($existing) {
                abort_unless(! $existing->is_admin && hash_equals((string) $existing->account_creation_fingerprint, $fingerprint), 409, 'This request has already been used.');

                return ['customer' => $this->resource($existing), 'replayed' => true, 'delivery' => 'not_repeated'];
            }
            if ($data['send_login'] && ! $this->mailReady()) {
                throw ValidationException::withMessages(['send_login' => 'Enable and save valid outgoing SMTP settings in General Settings before sending login information.']);
            }
            $customer = DB::transaction(function () use ($actor, $data, $fingerprint): User {
                $assignmentData = $this->prepareAssignment($data);
                $definition = $assignmentData['snapshot']['definition'];
                if ($assignmentData['snapshot']['type'] !== 'trial' || ($definition['type'] ?? 'trial') !== 'trial' || (int) ($definition['price_minor'] ?? -1) !== 0) {
                    throw ValidationException::withMessages(['package_id' => 'New accounts must start with a Free Trial package. Use Payments to activate a paid package.']);
                }
                $user = new User;
                $metadata['before'] = $user->only(['name', 'email', 'preferred_locale']);
                $metadata['after'] = array_intersect_key($data, array_flip(['name', 'email', 'preferred_locale']));
                $user->forceFill(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'is_admin' => false, 'managed_account' => true, 'account_status' => $data['status'], 'preferred_locale' => $data['preferred_locale'], 'account_note' => $data['note'] ?? null, 'created_by_admin_id' => $actor->id, 'account_creation_key' => $data['request_id'], 'account_creation_fingerprint' => $fingerprint])->save();
                $assignment = CustomerPackageAssignment::create([...$assignmentData, 'user_id' => $user->id, 'assigned_by' => $actor->id, 'source' => 'account_creation']);
                $this->event($user, $actor, 'created', ['status' => $user->account_status, ...$this->assignmentAudit($assignment)]);

                return $user->refresh();
            });
            $delivery = 'not_requested';
            if ($data['send_login']) {
                $delivery = Cache::lock('customer:'.$customer->id, 60)->block(5, fn (): string => $this->deliverLoginInformation($actor, $customer, $data['request_id'], $fingerprint, 'account_creation'));
            }

            return ['customer' => $this->resource($customer), 'replayed' => false, 'delivery' => $delivery];
        });
    }

    public function sendLoginInformation(User $actor, int $id, array $data): array
    {
        abort_unless($actor->is_admin, 403);
        $fingerprint = $this->fingerprint($actor, ['send_login_information', $id, $data]);

        return Cache::lock('customer:'.$id, 60)->block(5, function () use ($actor, $id, $data, $fingerprint): array {
            $user = $this->customer($id);
            $prior = CustomerAccountEvent::where('operation_key', $data['request_id'])->first();
            if ($prior) {
                abort_unless($prior->user_id === $id && $prior->type === 'welcome_email' && hash_equals((string) $prior->fingerprint, $fingerprint), 409);

                return ['delivery' => $prior->metadata['delivery'], 'replayed' => true];
            }
            abort_unless($user->account_status !== 'archived', 409, 'Restore the account before sending login information.');
            abort_unless($user->account_revision === (int) $data['revision'], 409, 'This account changed. Refresh before sending.');
            if (! $this->mailReady()) {
                throw ValidationException::withMessages(['send_login' => 'Enable and save outgoing SMTP settings in General Settings first.']);
            }
            abort_if(CustomerAccountEvent::where('user_id', $id)->where('type', 'welcome_email')->where('created_at', '>', now()->subMinute())->exists(), 429, 'Wait one minute before sending another login email.');

            return ['delivery' => $this->deliverLoginInformation($actor, $user, $data['request_id'], $fingerprint, 'admin_resend'), 'replayed' => false];
        });
    }

    private function deliverLoginInformation(User $actor, User $customer, string $requestId, string $fingerprint, string $source): string
    {
        $token = null;
        try {
            $token = Password::broker('users')->createToken($customer);
            $base = rtrim((string) config('app.url'), '/');
            $customer->notify(new CustomerWelcome(
                $base.route('password.reset', ['token' => $token, 'email' => $customer->email], false),
                $base.route('login', [], false),
                app(GeneralSettings::class)->current()['configuration']['name'],
                (int) config('auth.passwords.users.expire', 60)
            ));
            $delivery = 'sent';
        } catch (\Throwable) {
            if ($token !== null) {
                Password::broker('users')->deleteToken($customer);
            }
            $delivery = 'failed';
        }
        $this->event($customer, $actor, 'welcome_email', ['delivery' => $delivery, 'source' => $source], $requestId, $fingerprint);

        return $delivery;
    }

    public function upgradePackageFeatures(User $actor, int $id, int $packageId, int $versionNumber, int $revision, string $reason, string $requestId): array
    {
        abort_unless($actor->is_admin, 403);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500 || ! Str::isUuid($requestId)) {
            throw ValidationException::withMessages(['reason' => 'A reason and a valid request ID are required.']);
        }
        $fingerprint = $this->fingerprint($actor, ['package_features', $id, $packageId, $versionNumber, $revision, $reason]);

        return Cache::lock('customer:'.$id, 15)->block(5, fn (): array => DB::transaction(function () use ($actor, $id, $packageId, $versionNumber, $revision, $reason, $requestId, $fingerprint): array {
            $user = $this->customer($id, true);
            $prior = CustomerAccountEvent::where('operation_key', $requestId)->first();
            if ($prior) {
                abort_unless($prior->user_id === $id && hash_equals((string) $prior->fingerprint, $fingerprint), 409);

                return ['customer' => $this->resource($user), 'replayed' => true];
            }
            abort_unless($user->account_revision === $revision, 409, 'This account changed. Refresh and try again.');
            $package = Package::lockForUpdate()->findOrFail($packageId);
            abort_unless($package->status === 'active' && (int) $package->published_version === $versionNumber, 409, 'The published package changed.');
            $version = PackageVersion::where('package_id', $packageId)->where('number', $versionNumber)->firstOrFail();
            $periods = CustomerPackageAssignment::standing()->where('user_id', $id)->where('package_id', $packageId)->whereNull('closed_kind')->where('ends_at', '>', now())->lockForUpdate()->get();
            abort_if($periods->isEmpty(), 409, 'No current or upcoming period uses this package.');
            $changes = [];
            foreach ($periods as $period) {
                $old = $period->snapshot['definition'];
                $new = $version->definition;
                if (array_diff_key($old, ['features' => true]) !== array_diff_key($new, ['features' => true])) {
                    throw ValidationException::withMessages(['package_version' => 'Only feature additions can be applied here. Prices and allowance terms must be identical.']);
                }
                foreach ($old['features'] ?? [] as $key => $enabled) {
                    if ($enabled && ! ($new['features'][$key] ?? false)) {
                        throw ValidationException::withMessages(['package_version' => 'This update removes an existing feature. Review the package assignment instead.']);
                    }
                }
                if ((int) $period->snapshot['version'] === $versionNumber && $old === $new) {
                    continue;
                }
                $before = $this->assignmentAudit($period);
                $snapshot = $period->snapshot;
                $snapshot['version'] = $versionNumber;
                $snapshot['definition'] = $new;
                $period->forceFill(['package_version_id' => $version->id, 'snapshot' => $snapshot])->save();
                $changes[] = ['before' => $before, 'after' => $this->assignmentAudit($period)];
            }
            if ($changes !== []) {
                $user->account_revision++;
                $user->save();
                $this->event($user, $actor, 'edit', ['fields' => ['package_features'], 'reason' => $reason, 'package_change' => $changes[0], 'feature_version_changes' => $changes], $requestId, $fingerprint);
            }
            $user->unsetRelation('currentPackageAssignment');

            return ['customer' => $this->resource($user), 'replayed' => false, 'updated_periods' => count($changes)];
        }));
    }

    public function mutate(User $actor, int $id, array $data, string $action): array
    {
        $fingerprint = $this->fingerprint($actor, [$action, $id, $data]);

        return Cache::lock('customer:'.$id, 15)->block(5, fn (): array => DB::transaction(function () use ($actor, $id, $data, $action, $fingerprint): array {
            $user = $this->customer($id, true);
            $prior = CustomerAccountEvent::where('operation_key', $data['request_id'])->first();
            if ($prior) {
                abort_unless($prior->user_id === $id && hash_equals((string) $prior->fingerprint, $fingerprint), 409, 'This request has already been used.');

                return ['customer' => $this->resource($user), 'replayed' => true];
            }
            abort_unless($user->account_revision === (int) $data['revision'], 409, 'This account changed. Refresh and try again.');
            $metadata = [];
            if ($action === 'edit') {
                $emailChanged = $user->email !== $data['email'];
                $metadata = ['fields' => array_values(array_filter(['name', 'email', 'preferred_locale', 'account_note'], fn (string $field): bool => $user->$field !== ($data[$field === 'account_note' ? 'note' : $field] ?? null)))];
                $user->forceFill(['name' => $data['name'], 'email' => $data['email'], 'preferred_locale' => $data['preferred_locale'], 'account_note' => $data['note'] ?? null]);
                if (isset($data['package_id'])) {
                    $current = $user->currentPackageAssignment()->lockForUpdate()->first();
                    if (! $current || ! $this->assignmentMatches($current, $data)) {
                        abort_if(CustomerPackageAssignment::standing()->where('user_id', $user->id)->whereNull('closed_kind')->where('starts_at', '>', now())->when($current, fn ($query) => $query->where('id', '!=', $current->id))->exists(), 409, 'A scheduled period exists. Manage it in Subscriptions before correcting this assignment.');
                        $assignmentData = $this->prepareAssignment($data, $current);
                        $assignment = CustomerPackageAssignment::create([...$assignmentData, 'user_id' => $user->id, 'assigned_by' => $actor->id, 'source' => 'account_correction', 'supersedes_id' => $current?->id, 'allowance_starts_at' => $current?->allowance_starts_at ?? $current?->starts_at]);
                        $metadata['package_change'] = ['before' => $current ? $this->assignmentAudit($current) : null, 'after' => $this->assignmentAudit($assignment)];
                        $metadata['fields'][] = 'package';
                        $user->unsetRelation('currentPackageAssignment');
                    }
                }
                if ($emailChanged) {
                    $user->email_verified_at = null;
                    $this->revoke($user);
                }
            } elseif ($action === 'reset_password') {
                $user->password = Hash::make($data['password']);
                $this->revoke($user);
            } elseif ($action === 'revoke_sessions') {
                $this->revoke($user);
            } else {
                $transitions = ['suspend' => ['active', 'suspended'], 'reactivate' => ['suspended', 'active'], 'archive' => [['active', 'suspended'], 'archived'], 'restore' => ['archived', 'suspended']];
                abort_unless(isset($transitions[$action]), 422);
                [$from,$to] = $transitions[$action];
                abort_unless(in_array($user->account_status, (array) $from, true), 409, 'The account status changed. Refresh and try again.');
                $metadata = ['from' => $user->account_status, 'to' => $to];
                $user->account_status = $to;
                $this->revoke($user);
            }
            if (! empty($data['reason'])) {
                $metadata['reason'] = $data['reason'];
            }
            $user->account_revision++;
            $user->save();
            $this->event($user, $actor, $action, $metadata, $data['request_id'], $fingerprint);

            return ['customer' => $this->resource($user->refresh()), 'replayed' => false];
        }));
    }

    private function prepareAssignment(array $data, ?CustomerPackageAssignment $current = null): array
    {
        $effective = $current?->effectivePackage();
        $keepTerms = $current && $current->package_id === (int) $data['package_id'] && (int) $effective['snapshot']['version'] === (int) $data['package_version'];
        if ($keepTerms) {
            $snapshot = $effective['snapshot'];
            $packageId = $current->package_id;
            $versionId = $effective['version_id'];
        } else {
            $package = Package::lockForUpdate()->find($data['package_id']);
            if (! $package || $package->status !== 'active' || $package->published_version < 1) {
                throw ValidationException::withMessages(['package_id' => 'Choose an active, published package. Refresh the package list and try again.']);
            }
            abort_unless($package->published_version === (int) $data['package_version'], 409, 'This package changed. Refresh and choose its latest version.');
            $version = PackageVersion::where('package_id', $package->id)->where('number', $package->published_version)->first();
            if (! $version || ($version->definition['validity_days'] ?? 0) < 1) {
                throw ValidationException::withMessages(['package_id' => 'The selected package has no valid published version.']);
            }
            $snapshot = ['name' => $package->name, 'code' => $package->code, 'type' => $package->type, 'version' => $version->number, 'definition' => $version->definition];
            $packageId = $package->id;
            $versionId = $version->id;
        }
        $snapshot['validity_mode'] = $data['validity_mode'];
        $startsAt = CarbonImmutable::createFromFormat('!Y-m-d', $data['starts_on'], config('app.timezone', 'UTC'));
        $endsAt = CarbonImmutable::createFromFormat('!Y-m-d', $data['ends_on'], config('app.timezone', 'UTC'));
        $today = CarbonImmutable::today(config('app.timezone', 'UTC'));
        $minimum = isset($data['revision']) ? $today->subDays(3650) : $today;
        if ($startsAt->lessThan($minimum) || $startsAt->greaterThan($today->addDays(3650))) {
            throw ValidationException::withMessages(['starts_on' => 'Choose a start date within the supported range.']);
        }
        if ($endsAt->lessThanOrEqualTo($startsAt) || $endsAt->greaterThan($startsAt->addDays(3650))) {
            throw ValidationException::withMessages(['ends_on' => 'The end date must be after the start date, within a maximum period of 3650 days.']);
        }
        if ($data['validity_mode'] === 'package' && ! $endsAt->equalTo($startsAt->addDays((int) $snapshot['definition']['validity_days']))) {
            throw ValidationException::withMessages(['ends_on' => 'Use the selected package duration, or choose a custom period.']);
        }

        if ($current && $current->starts_at->setTimezone(config('app.timezone', 'UTC'))->toDateString() === $data['starts_on']) {
            $startsAt = $current->starts_at;
        }
        if ($current && $current->ends_at->setTimezone(config('app.timezone', 'UTC'))->toDateString() === $data['ends_on']) {
            $endsAt = $current->ends_at;
        }

        return ['package_id' => $packageId, 'package_version_id' => $versionId, 'snapshot' => $snapshot, 'starts_at' => $startsAt, 'ends_at' => $endsAt];
    }

    private function assignmentMatches(CustomerPackageAssignment $current, array $data): bool
    {
        return $current->package_id === (int) $data['package_id']
            && (int) $current->effectiveSnapshot()['version'] === (int) $data['package_version']
            && $current->validityMode() === $data['validity_mode']
            && $current->starts_at->toDateString() === $data['starts_on']
            && $current->ends_at->toDateString() === $data['ends_on'];
    }

    private function assignmentAudit(CustomerPackageAssignment $assignment): array
    {
        return ['assignment_id' => $assignment->id, 'package_id' => $assignment->package_id, 'package_name' => $assignment->snapshot['name'], 'package_version' => $assignment->snapshot['version'], 'starts_at' => $assignment->starts_at->toIso8601String(), 'ends_at' => $assignment->ends_at->toIso8601String()];
    }

    private function revoke(User $user): void
    {
        $user->auth_version++;
        $user->remember_token = Str::random(60);
        DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))->where('email', $user->getOriginal('email'))->delete();
        // Customer sessions can share a browser with an administrator. The version check
        // revokes only the customer guard and preserves that administrator's session.
    }

    private function event(User $user, User $actor, string $type, array $metadata, ?string $key = null, ?string $fingerprint = null): void
    {
        CustomerAccountEvent::create(['user_id' => $user->id, 'actor_id' => $actor->id, 'type' => $type, 'metadata' => $metadata, 'operation_key' => $key, 'fingerprint' => $fingerprint]);
    }

    private function fingerprint(User $actor, array $data): string
    {
        return hash_hmac('sha256', json_encode([$actor->id, $data], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function details(User $user): array
    {
        return ['mailReady' => $this->mailReady(), 'customer' => $this->resource($user), 'created_by' => User::whereKey($user->created_by_admin_id)->value('name'), 'activity' => CustomerAccountEvent::where('user_id', $user->id)->with('actor:id,name')->latest('id')->limit(50)->get()->map(fn (CustomerAccountEvent $event): array => ['id' => $event->id, 'type' => $event->type, 'actor' => $event->actor?->name, 'metadata' => $event->metadata, 'created_at' => $event->created_at->toIso8601String()])->all(), 'projects' => MediaProject::where('user_id', $user->id)->latest()->limit(20)->get(['id', 'title', 'status', 'created_at'])->toArray()];
    }
}
