<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RevenueReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RevenueController extends Controller
{
    private function filters(Request $request): array
    {
        $input = $request->validate([
            'currency' => ['nullable', Rule::in(['all', ...RevenueReport::CURRENCIES])],
            'dates' => ['nullable', Rule::in(['1', '7', '30', 'month', 'last_month', 'custom', 'all'])],
            'from' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'],
            'to' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
            'package' => ['nullable', 'integer', 'min:1'], 'method' => ['nullable', 'integer', 'min:1'],
            'purpose' => ['nullable', Rule::in(['new', 'renewal', 'change'])],
            'type' => ['nullable', Rule::in(['payment', 'refund'])], 'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'amount'])],
            'group' => ['nullable', Rule::in(['auto', 'day', 'week', 'month'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['nullable', Rule::in([10, 25, 50])],
        ]);
        $filters = array_replace(['currency' => 'all', 'dates' => '30', 'from' => '', 'to' => '', 'package' => '', 'method' => '', 'purpose' => '',
            'type' => '', 'q' => '', 'sort' => 'newest', 'group' => 'auto', 'page' => 1, 'per_page' => 10], array_filter($input, fn ($v) => $v !== null && $v !== ''));
        $filters['per_page'] = (int) $filters['per_page'];
        $filters['page'] = (int) $filters['page'];
        if ($filters['sort'] === 'amount' && $filters['currency'] === 'all') {
            throw ValidationException::withMessages(['sort' => 'Select one currency before sorting by amount.']);
        }

        return $filters;
    }

    public function index(Request $request, RevenueReport $report): JsonResponse|Response
    {
        $data = $report->report($this->filters($request));

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'private, no-store')
            : Inertia::render('admin/revenue', ['revenue' => $data]);
    }

    public function show(string $type, int $entry, RevenueReport $report): JsonResponse
    {
        return DB::transaction(fn () => response()->json($report->detail($type, $entry))->header('Cache-Control', 'private, no-store'));
    }

    public function export(Request $request, RevenueReport $report): StreamedResponse
    {
        $filters = $this->filters($request);
        $bounds = $report->bounds($filters);
        $query = $report->sorted($report->query($filters, $bounds, true), $filters);

        return response()->streamDownload(function () use ($query, $report, $bounds): void {
            DB::transaction(function () use ($query, $report, $bounds): void {
                $output = fopen('php://output', 'w');
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, ['Event', 'Payment', 'Date and time', 'Timezone', 'Customer', 'Email', 'Package', 'Purchased version', 'Purpose', 'Receiving method', 'Currency', 'Incoming amount', 'Refunded amount', 'Status'], ',', '"', '');
                foreach ($query->lazy(500) as $row) {
                    $entry = $report->present($row);
                    $money = intdiv($entry['amount_minor'], 100).'.'.str_pad((string) ($entry['amount_minor'] % 100), 2, '0', STR_PAD_LEFT);
                    $cells = [$entry['code'], $entry['payment_code'], CarbonImmutable::parse($entry['occurred_at'])->setTimezone($bounds['timezone'])->format('Y-m-d H:i:s'), $bounds['timezone'],
                        $entry['customer']['name'], $entry['customer']['email'], $entry['package']['name'], $entry['package']['version'],
                        $entry['purpose'], $entry['method']['name'], $entry['currency'], $entry['type'] === 'payment' ? $money : '', $entry['type'] === 'refund' ? $money : '', $entry['status']];
                    fputcsv($output, array_map(fn ($value) => preg_match('/^[\s\x00-\x20]*[=+@-]/u', (string) $value) ? "'".$value : $value, $cells), ',', '"', '');
                }
                fclose($output);
            });
        }, 'revenue-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
