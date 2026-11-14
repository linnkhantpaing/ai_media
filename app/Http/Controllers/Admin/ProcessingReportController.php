<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\User;
use App\Services\ProcessingReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcessingReportController extends Controller
{
    private function filters(Request $request): array
    {
        $values = $request->validate([
            'dates' => ['nullable', Rule::in(['1', '7', '30', 'month', 'last_month', 'all', 'custom'])],
            'from' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'],
            'to' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
            'package' => ['nullable', 'integer', 'min:1'], 'customer' => ['nullable', 'integer', 'min:1'],
            'mode' => ['nullable', Rule::in(['subs', 'dub'])], 'source' => ['nullable', Rule::in(['upload', 'youtube'])],
            'view' => ['nullable', Rule::in(['customers', 'packages', 'stages'])],
            'q' => ['nullable', 'string', 'max:100'], 'customer_q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['processing', 'exports', 'failures', 'storage', 'name'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['nullable', Rule::in([10, 25, 50])],
        ]);

        return array_replace(['dates' => '30', 'from' => '', 'to' => '', 'package' => '', 'customer' => '', 'mode' => '', 'source' => '',
            'view' => 'customers', 'q' => '', 'sort' => 'processing', 'page' => 1, 'per_page' => 10], array_filter($values, fn ($value) => $value !== null), ['page' => (int) ($values['page'] ?? 1), 'per_page' => (int) ($values['per_page'] ?? 10)]);
    }

    public function index(Request $request, ProcessingReport $report): Response|JsonResponse
    {
        $filters = $this->filters($request);
        $data = $report->report($filters);
        unset($data['_export']);
        $customers = User::where('is_admin', false);
        if (filled($filters['customer_q'] ?? '')) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['customer_q']).'%';
            $customers->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("email LIKE ? ESCAPE '!'", [$term]));
        }
        $options = $customers->orderBy('name')->limit(50)->get(['id', 'name', 'email']);
        if ($filters['customer'] !== '' && ! $options->contains('id', (int) $filters['customer'])) {
            if ($selected = User::where('is_admin', false)->find($filters['customer'], ['id', 'name', 'email'])) {
                $options->prepend($selected);
            }
        }
        $data['options'] = ['packages' => Package::orderBy('name')->get(['id', 'name', 'code'])->toArray(), 'customers' => $options->toArray()];
        if ($request->expectsJson()) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('admin/processing-report', ['report' => $data]);
    }

    public function show(Request $request, ProcessingReport $report, int $customer): JsonResponse
    {
        $filters = $this->filters($request);
        $filters['customer'] = $customer;

        return response()->json($report->report($filters, $customer))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, ProcessingReport $report): StreamedResponse
    {
        $filters = $this->filters($request);
        $data = $report->report($filters);
        $view = $filters['view'];

        return response()->streamDownload(function () use ($data, $view): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            $prefix = ['Report view', 'From', 'Through', 'Timezone'];
            $columns = match ($view) {
                'stages' => ['Stage', 'Completed jobs', 'Failed jobs', 'Cancelled jobs', 'Average completed processing seconds'],
                'packages' => ['Package', 'Customers with activity', 'Projects with activity', 'Net charged source-video seconds', 'Successful exports', 'Failed exports', 'Cancelled exports', 'Export success rate percent', 'Average successful export processing seconds'],
                default => ['Customer', 'Email', 'Historical packages', 'Projects with activity', 'Net charged source-video seconds', 'Successful exports', 'Failed exports', 'Cancelled exports', 'Export success rate percent', 'Average successful export processing seconds', 'Current verified storage bytes', 'Current reserved processing seconds'],
            };
            fputcsv($stream, [...$prefix, ...$columns], ',', '"', '');
            foreach ($data['_export'] as $row) {
                $values = match ($view) {
                    'stages' => [$row['id'], $row['completed'], $row['failed'], $row['cancelled'], $row['average_duration']],
                    'packages' => [$row['name'] ?? 'Unknown package', $row['customers'], $row['projects'], $row['seconds'], $row['successful'], $row['failed'], $row['cancelled'], $row['success_rate'], $row['average_duration']],
                    default => [$row['name'], $row['email'], implode('; ', array_map(fn ($package) => $package['name'].' v'.$package['version'], $row['packages'])),
                        $row['projects'], $row['seconds'], $row['successful'], $row['failed'], $row['cancelled'], $row['success_rate'], $row['average_duration'], $row['storage'], $row['reserved']],
                };
                $values = array_map(fn ($value) => is_string($value) && preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $value) ? "'".$value : $value, $values);
                fputcsv($stream, [$view, $data['range']['from'], $data['range']['to'], $data['range']['timezone'], ...$values], ',', '"', '');
            }
            fclose($stream);
        }, 'zatlan-processing-'.$view.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
