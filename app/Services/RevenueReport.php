<?php

namespace App\Services;

use App\Models\CustomerPackageAssignment;
use App\Models\Package;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevenueReport
{
    public const CURRENCIES = ['MMK', 'THB', 'USD'];

    public function timezone(): string
    {
        return app(GeneralSettings::class)->publicData()['timezone'] ?? 'UTC';
    }

    public function events(): Builder
    {
        $base = DB::table('payment_transactions as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->join('package_versions as v', 'v.id', '=', 'p.package_version_id')
            ->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->where('p.status', 'approved')->whereNotNull('p.approved_at')->whereNotNull('p.received_minor')
            ->whereIn('p.currency', self::CURRENCIES);
        $columns = ['p.id as payment_id', 'p.currency', 'p.user_id', 'p.purpose', 'p.package_snapshot',
            'p.receiving_snapshot as receiving_cipher', 'p.package_version_id', 'p.subscription_id',
            'v.package_id', 'p.payment_method_id', 'u.name as customer_name', 'u.email as customer_email',
            'm.name as method_name', 'm.type as method_type'];
        $payments = (clone $base)->select($columns)->selectRaw("'payment' AS type, p.id AS event_id, p.received_minor AS amount_minor, p.approved_at AS occurred_at");
        $refunds = (clone $base)->join('payment_refunds as r', 'r.payment_transaction_id', '=', 'p.id')
            ->where('r.status', 'completed')->whereNotNull('r.completed_at')
            ->select($columns)->selectRaw("'refund' AS type, r.id AS event_id, r.amount_minor AS amount_minor, r.completed_at AS occurred_at");

        return DB::query()->fromSub($payments->unionAll($refunds), 'e');
    }

    public function bounds(array $filters): array
    {
        $zone = $this->timezone();
        $today = CarbonImmutable::now($zone)->startOfDay();
        $end = $today->addDay();
        $start = match ($filters['dates']) {
            '1' => $today,
            '7' => $today->subDays(6),
            '30' => $today->subDays(29),
            'month' => $today->startOfMonth(),
            'last_month' => $today->subMonthNoOverflow()->startOfMonth(),
            'custom' => CarbonImmutable::parse($filters['from'], $zone)->startOfDay(),
            default => CarbonImmutable::parse($this->events()->min('e.occurred_at') ?? $today->subDays(29), 'UTC')->setTimezone($zone)->startOfDay(),
        };
        if ($filters['dates'] === 'last_month') {
            $end = $today->startOfMonth();
        } elseif ($filters['dates'] === 'custom') {
            $end = CarbonImmutable::parse($filters['to'], $zone)->addDay()->startOfDay();
        }
        if ($start >= $end) {
            throw ValidationException::withMessages(['to' => 'Choose an end date on or after the start date.']);
        }

        return ['from' => $start->utc(), 'until' => $end->utc(), 'start' => $start, 'end' => $end, 'timezone' => $zone];
    }

    public function query(array $filters, array $bounds, bool $activity = false): Builder
    {
        $query = $this->events()->where('e.occurred_at', '>=', $bounds['from'])->where('e.occurred_at', '<', $bounds['until'])
            ->where('e.occurred_at', '<=', now());
        if ($filters['currency'] !== 'all') {
            $query->where('e.currency', $filters['currency']);
        }
        foreach (['package' => 'package_id', 'method' => 'payment_method_id', 'purpose' => 'purpose'] as $key => $column) {
            if ($filters[$key] !== '') {
                $query->where('e.'.$column, $filters[$key]);
            }
        }
        if ($activity) {
            if ($filters['type'] !== '') {
                $query->where('e.type', $filters['type']);
            }
            if (trim($filters['q']) !== '') {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
                $query->where(function (Builder $search) use ($term, $filters): void {
                    foreach (['customer_name', 'customer_email', 'method_name'] as $column) {
                        $search->orWhereRaw('e.'.$column." LIKE ? ESCAPE '!'", [$term]);
                    }
                    if (preg_match('/^(PYT|RFD)-0*(\d+)$/i', trim($filters['q']), $match)) {
                        if (strtoupper($match[1]) === 'PYT') {
                            $search->orWhere('e.payment_id', (int) $match[2]);
                        } else {
                            $search->orWhere(fn ($q) => $q->where('e.type', 'refund')->where('e.event_id', (int) $match[2]));
                        }
                    } elseif (ctype_digit(trim($filters['q']))) {
                        $search->orWhere('e.payment_id', (int) trim($filters['q']));
                    }
                });
            }
        }

        return $query;
    }

    private function sums(Builder $query, array $groups): array
    {
        $summary = $query->select($groups)->selectRaw("SUM(CASE WHEN e.type = 'payment' THEN e.amount_minor ELSE 0 END) AS gross,
            SUM(CASE WHEN e.type = 'refund' THEN e.amount_minor ELSE 0 END) AS refunds,
            SUM(CASE WHEN e.type = 'payment' THEN 1 ELSE 0 END) AS payments,
            SUM(CASE WHEN e.type = 'refund' THEN 1 ELSE 0 END) AS refund_count,
            COUNT(DISTINCT CASE WHEN e.type = 'payment' THEN e.user_id END) AS customers")
            ->groupBy($groups)->get();

        return $summary->map(function ($row): array {
            $data = (array) $row;
            foreach (['gross', 'refunds', 'payments', 'refund_count', 'customers'] as $key) {
                $data[$key] = (int) $data[$key];
            }
            $data['net'] = $data['gross'] - $data['refunds'];

            return $data;
        })->all();
    }

    private function buckets(Builder $query, array $bounds, string $requested): array
    {
        $days = (int) ceil($bounds['start']->diffInDays($bounds['end']));
        $group = $requested === 'auto' ? ($days <= 45 ? 'day' : ($days <= 210 ? 'week' : 'month')) : $requested;
        if ($group === 'day' && $days > 120) {
            $group = 'week';
        }
        if ($group === 'week' && $days > 840) {
            $group = 'month';
        }
        $key = fn (CarbonImmutable $date) => match ($group) {
            'week' => $date->startOfWeek()->toDateString(), 'month' => $date->startOfMonth()->toDateString(), default => $date->toDateString(),
        };
        $buckets = [];
        $cursor = $bounds['start'];
        while ($cursor < $bounds['end']) {
            $date = $key($cursor);
            $buckets[$date] = ['date' => $date, 'values' => array_fill_keys(self::CURRENCIES, ['gross' => 0, 'refunds' => 0, 'net' => 0])];
            $cursor = match ($group) {
                'week' => $cursor->startOfWeek()->addWeek(), 'month' => $cursor->startOfMonth()->addMonth(), default => $cursor->addDay(),
            };
        }
        foreach ($query->select(['e.type', 'e.currency', 'e.amount_minor', 'e.occurred_at'])->cursor() as $event) {
            $date = $key(CarbonImmutable::parse($event->occurred_at, 'UTC')->setTimezone($bounds['timezone']));
            $field = $event->type === 'payment' ? 'gross' : 'refunds';
            $buckets[$date]['values'][$event->currency][$field] += (int) $event->amount_minor;
        }
        foreach ($buckets as &$bucket) {
            foreach ($bucket['values'] as &$value) {
                $value['net'] = $value['gross'] - $value['refunds'];
            }
        }

        return ['group' => $group, 'points' => array_values($buckets)];
    }

    public function sorted(Builder $query, array $filters): Builder
    {
        if ($filters['sort'] === 'amount') {
            $query->orderByDesc('e.amount_minor');
        }

        return $query->orderBy('e.occurred_at', $filters['sort'] === 'oldest' ? 'asc' : 'desc')
            ->orderBy('e.type')->orderByDesc('e.event_id');
    }

    public function present(object $row): array
    {
        $snapshot = json_decode($row->package_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $method = ['name' => $row->method_name, 'type' => $row->method_type];
        try {
            $receiving = json_decode(Crypt::decryptString($row->receiving_cipher), true, 512, JSON_THROW_ON_ERROR)['receiving'] ?? [];
            $method = ['name' => $receiving['name'] ?? $method['name'], 'type' => $receiving['type'] ?? $method['type']];
        } catch (DecryptException|\JsonException) {
            // A historical display label may fall back to the existing method; monetary values remain readable.
        }
        $code = ($row->type === 'payment' ? 'PYT-' : 'RFD-').str_pad((string) $row->event_id, 7, '0', STR_PAD_LEFT);

        return ['id' => $row->type.':'.$row->event_id, 'type' => $row->type, 'code' => $code,
            'payment_code' => 'PYT-'.str_pad((string) $row->payment_id, 7, '0', STR_PAD_LEFT),
            'customer' => ['name' => $row->customer_name, 'email' => $row->customer_email],
            'package' => ['name' => $snapshot['name'] ?? '', 'code' => $snapshot['code'] ?? '', 'version' => (int) ($snapshot['version'] ?? 1)],
            'purpose' => $row->purpose, 'method' => $method, 'currency' => $row->currency,
            'amount_minor' => (int) $row->amount_minor, 'occurred_at' => CarbonImmutable::parse($row->occurred_at, 'UTC')->toIso8601String(),
            'status' => $row->type === 'payment' ? 'approved' : 'completed',
            'detail_url' => route('admin.revenue.show', ['type' => $row->type, 'entry' => $row->event_id], absolute: false)];
    }

    public function report(array $filters): array
    {
        return DB::transaction(fn () => $this->buildReport($filters));
    }

    private function buildReport(array $filters): array
    {
        $bounds = $this->bounds($filters);
        $query = $this->query($filters, $bounds);
        $amounts = array_fill_keys(self::CURRENCIES, ['gross' => 0, 'refunds' => 0, 'net' => 0, 'payments' => 0, 'refund_count' => 0, 'customers' => 0]);
        foreach ($this->sums(clone $query, ['e.currency']) as $summary) {
            $currency = $summary['currency'];
            unset($summary['currency']);
            $amounts[$currency] = $summary;
        }
        $counts = ['payments' => array_sum(array_column($amounts, 'payments')), 'refunds' => array_sum(array_column($amounts, 'refund_count')),
            'customers' => (clone $query)->where('e.type', 'payment')->distinct()->count('e.user_id')];
        $packageNames = Package::query()->pluck('name', 'id');
        $methodNames = PaymentMethod::query()->pluck('name', 'id');
        $packages = array_map(function ($row) use ($packageNames): array {
            $row['name'] = $packageNames[$row['package_id']] ?? 'Archived package';
            $row['package_id'] = (int) $row['package_id'];

            return $row;
        }, $this->sums(clone $query, ['e.package_id', 'e.currency']));
        $methods = array_map(function ($row) use ($methodNames): array {
            $row['name'] = $methodNames[$row['payment_method_id']] ?? 'Archived method';
            $row['payment_method_id'] = (int) $row['payment_method_id'];

            return $row;
        }, $this->sums(clone $query, ['e.payment_method_id', 'e.currency']));
        $activity = $this->query($filters, $bounds, true);
        $total = (clone $activity)->count();
        $pages = max(1, (int) ceil($total / $filters['per_page']));
        $page = min($filters['page'], $pages);
        $items = $this->sorted($activity, $filters)->offset(($page - 1) * $filters['per_page'])->limit($filters['per_page'])->get()->map(fn ($row) => $this->present($row))->all();

        return ['filters' => $filters, 'summary' => ['amounts' => $amounts, 'counts' => $counts],
            'trend' => $this->buckets(clone $query, $bounds, $filters['group']), 'packages' => $packages, 'methods' => $methods, 'items' => $items,
            'options' => ['packages' => Package::orderBy('name')->get(['id', 'name', 'code'])->toArray(), 'methods' => PaymentMethod::orderBy('name')->get(['id', 'name', 'currency'])->toArray()],
            'range' => ['from' => $bounds['start']->toDateString(), 'to' => $bounds['end']->subDay()->toDateString(), 'timezone' => $bounds['timezone']],
            'today' => CarbonImmutable::now($bounds['timezone'])->toDateString(), 'updated_at' => now()->toIso8601String(),
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'from' => $total ? ($page - 1) * $filters['per_page'] + 1 : null, 'to' => $total ? ($page - 1) * $filters['per_page'] + count($items) : null],
            'endpoints' => ['index' => route('admin.revenue.index', absolute: false), 'export' => route('admin.revenue.export', absolute: false)]];
    }

    public function detail(string $type, int $id): array
    {
        $row = $this->events()->where('e.type', $type)->where('e.event_id', $id)->where('e.occurred_at', '<=', now())->first();
        abort_unless($row, 404);
        $result = $this->present($row);
        $payment = DB::table('payment_transactions')->find($row->payment_id, ['id', 'approved_at', 'received_minor', 'subscription_id']);
        $refunded = (int) DB::table('payment_refunds')->where('payment_transaction_id', $payment->id)->where('status', 'completed')->whereNotNull('completed_at')->sum('amount_minor');
        $assignment = $payment->subscription_id ? CustomerPackageAssignment::find($payment->subscription_id) : null;
        $result['lifetime'] = ['gross' => (int) $payment->received_minor, 'refunds' => $refunded, 'net' => (int) $payment->received_minor - $refunded];
        $result['period'] = $assignment ? ['starts_at' => $assignment->starts_at->toIso8601String(), 'ends_at' => $assignment->ends_at->toIso8601String(), 'status' => $assignment->status()] : null;
        $result['payment_approved_at'] = CarbonImmutable::parse($payment->approved_at, 'UTC')->toIso8601String();
        $result['related_url'] = route('admin.payments.index', ['q' => $result['payment_code']], absolute: false);

        return $result;
    }
}
