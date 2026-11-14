<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class CustomerPackageAssignment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'allowance_starts_at' => 'immutable_datetime'];
    }

    public function scopeStanding(Builder $query): void
    {
        $query->whereNotExists(function (QueryBuilder $replacement): void {
            $replacement->selectRaw('1')->from('customer_package_assignments as replacements')->whereColumn('replacements.supersedes_id', 'customer_package_assignments.id');
        });
        $query->where(function (Builder $query): void {
            $query->where('source', '!=', 'legacy')->orWhereNotExists(function (QueryBuilder $newer): void {
                $newer->selectRaw('1')->from('customer_package_assignments as newer_legacy')
                    ->whereColumn('newer_legacy.user_id', 'customer_package_assignments.user_id')
                    ->whereColumn('newer_legacy.id', '>', 'customer_package_assignments.id')->where('newer_legacy.source', 'legacy');
            });
        });
    }

    public static function currentIds(): QueryBuilder
    {
        $now = now();
        $choice = self::query()->standing()->select('id')->whereColumn('user_id', 'subscription_customers.id')
            ->where(fn (Builder $query) => $query->whereNull('closed_kind')->orWhere('closed_kind', 'ended'))
            ->orderByRaw('CASE WHEN closed_kind IS NULL AND starts_at <= ? AND ends_at > ? THEN 0 WHEN closed_kind IS NULL AND starts_at > ? THEN 1 ELSE 2 END', [$now, $now, $now])
            ->orderByRaw('CASE WHEN closed_kind IS NULL AND starts_at > ? THEN starts_at END ASC', [$now])
            ->orderByDesc('starts_at')->orderByDesc('id')->limit(1);

        return DB::table('users as subscription_customers')->selectSub($choice, 'assignment_id');
    }

    public function scopeCurrent(Builder $query): void
    {
        $query->whereIn('customer_package_assignments.id', self::currentIds());
    }

    /**
     * Resolve live access from the latest published package while retaining the
     * purchased snapshot for expired periods, payment history and audit records.
     *
     * @return array{snapshot: array, version_id: int|null}
     */
    public function effectivePackage(): array
    {
        $original = ['snapshot' => $this->snapshot ?? [], 'version_id' => $this->package_version_id];
        if (! $this->exists || ! $this->ends_at || ! $this->ends_at->isFuture() || $this->closed_kind !== null || $this->status() === 'replaced') {
            return $original;
        }
        $latest = PackageVersion::query()->join('packages', 'packages.id', '=', 'package_versions.package_id')
            ->where('packages.id', $this->package_id)->whereColumn('package_versions.number', 'packages.published_version')
            ->first(['package_versions.*', 'packages.name as current_name', 'packages.code as current_code', 'packages.type as current_type']);
        if (! $latest) {
            return $original;
        }

        return ['snapshot' => array_replace($original['snapshot'], [
            'name' => $latest->definition['name'] ?? $latest->current_name,
            'code' => $latest->definition['code'] ?? $latest->current_code,
            'type' => $latest->definition['type'] ?? $latest->current_type,
            'version' => $latest->number, 'definition' => $latest->definition,
        ]), 'version_id' => $latest->id];
    }

    public function effectiveSnapshot(): array
    {
        return $this->effectivePackage()['snapshot'];
    }

    public function validityMode(?array $effective = null): string
    {
        $effective ??= $this->effectiveSnapshot();
        $days = (int) $this->starts_at->startOfDay()->diffInDays($this->ends_at->startOfDay());

        return ($this->snapshot['validity_mode'] ?? 'package') === 'custom' || $days !== (int) ($effective['definition']['validity_days'] ?? 0) ? 'custom' : 'package';
    }

    public function status(): string
    {
        if (($this->source === 'legacy' && self::where('user_id', $this->user_id)->where('source', 'legacy')->where('id', '>', $this->id)->exists()) || self::where('supersedes_id', $this->id)->exists()) {
            return 'replaced';
        }
        if ($this->closed_kind !== null) {
            return in_array($this->closed_kind, ['ended', 'cancelled'], true) ? $this->closed_kind : 'replaced';
        }
        if ($this->starts_at->isFuture()) {
            return 'upcoming';
        }

        return $this->ends_at->lessThanOrEqualTo(now()) ? 'expired' : 'active';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
