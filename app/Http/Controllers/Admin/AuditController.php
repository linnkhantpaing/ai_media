<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(['accounts', 'packages', 'payments', 'settings', 'security', 'operations'])],
            'actor' => ['nullable', Rule::in(['admin', 'customer', 'system', 'unknown'])],
            'result' => ['nullable', Rule::in(['success', 'failed', 'blocked', 'unsuccessful'])],
            'sensitive' => ['nullable', 'boolean'],
            'dates' => ['nullable', Rule::in(['1', '7', '30', 'all', 'custom'])],
            'from' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['nullable', Rule::in([10, 25, 50])],
        ]);

        return array_replace(['q' => '', 'category' => '', 'actor' => '', 'result' => '', 'sensitive' => false, 'dates' => '30', 'from' => '', 'to' => '', 'sort' => 'newest', 'page' => 1, 'per_page' => 10],
            array_filter($data, fn ($value) => $value !== null));
    }

    private function query(array $f, bool $category = true): Builder
    {
        $query = AuditEvent::query();
        if ($category && $f['category']) {
            $query->where('category', $f['category']);
        }
        foreach (['actor' => 'actor_kind', 'result' => 'result'] as $key => $column) {
            if ($key === 'result' && $f[$key] === 'unsuccessful') {
                $query->whereIn('result', ['failed', 'blocked']);

                continue;
            }
            if ($f[$key]) {
                $query->where($column, $f[$key]);
            }
        }
        if ($f['sensitive']) {
            $query->where('sensitive', true);
        }
        if ($f['dates'] === 'custom') {
            $query->where('occurred_at', '>=', $f['from'].' 00:00:00')->where('occurred_at', '<', CarbonImmutable::parse($f['to'], 'UTC')->addDay());
        } elseif ($f['dates'] !== 'all') {
            $query->where('occurred_at', '>=', now()->subDays((int) $f['dates']));
        }
        if (trim($f['q']) !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($f['q'])).'%';
            $query->where(function (Builder $q) use ($term, $f): void {
                foreach (['actor_name', 'actor_email', 'target_label', 'target_id', 'action', 'source_id'] as $field) {
                    $q->orWhereRaw($field." LIKE ? ESCAPE '!'", [$term]);
                }
                if (preg_match('/^(?:AUD-)?0*(\\d+)$/i', trim($f['q']), $match)) {
                    $q->orWhere('id', (int) $match[1]);
                }
            });
        }

        return $query;
    }

    public function index(Request $request): Response|JsonResponse
    {
        $f = $this->filters($request);
        $query = $this->query($f);
        $sensitiveColumn = $query->getQuery()->getGrammar()->wrap('sensitive');
        $summary = (clone $query)->selectRaw("COUNT(*) AS total, SUM(CASE WHEN result = 'success' THEN 1 ELSE 0 END) AS successful, SUM(CASE WHEN result IN ('failed','blocked') THEN 1 ELSE 0 END) AS failed, SUM(CASE WHEN {$sensitiveColumn} = 1 THEN 1 ELSE 0 END) AS sensitive_count")->first();
        $tabs = $this->query($f, false)->selectRaw('category, COUNT(*) AS total')->groupBy('category')->pluck('total', 'category')->map(fn ($n) => (int) $n)->all();
        $pages = max(1, (int) ceil((int) $summary->total / (int) $f['per_page']));
        $page = (clone $query)->orderBy('occurred_at', $f['sort'] === 'oldest' ? 'asc' : 'desc')->orderBy('id', $f['sort'] === 'oldest' ? 'asc' : 'desc')
            ->paginate((int) $f['per_page'], ['*'], 'page', min((int) $f['page'], $pages));
        $data = ['items' => $page->getCollection()->map(fn ($event) => $this->present($event))->all(),
            'summary' => ['total' => (int) $summary->total, 'successful' => (int) $summary->successful, 'failed' => (int) $summary->failed, 'sensitive' => (int) $summary->sensitive_count],
            'tabs' => $tabs, 'filters' => $f, 'today' => now()->toDateString(),
            'pagination' => ['page' => $page->currentPage(), 'pages' => $pages, 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'endpoints' => ['index' => route('admin.audit.index', absolute: false), 'export' => route('admin.audit.export', absolute: false)]];

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'private, no-store')
            : Inertia::render('admin/audit-security', ['audit' => $data]);
    }

    private function present(AuditEvent $event, bool $detail = false): array
    {
        $data = ['id' => $event->id, 'code' => 'AUD-'.str_pad((string) $event->id, 7, '0', STR_PAD_LEFT),
            'category' => $event->category, 'action' => $event->action, 'result' => $event->result, 'sensitive' => $event->sensitive,
            'actor' => ['name' => $event->actor_name, 'email' => $event->actor_email, 'kind' => $event->actor_kind, 'id' => $event->actor_id],
            'target' => $event->target_label, 'target_type' => $event->target_type, 'target_id' => $event->target_id,
            'ip' => $event->ip_address, 'occurred_at' => $event->occurred_at->toIso8601String(),
            'detail_url' => route('admin.audit.show', $event->id, absolute: false)];
        if ($detail) {
            $data += ['details' => $event->details, 'source' => $event->source, 'source_id' => $event->source_id,
                'browser' => $event->browser, 'request_id' => $event->request_id, 'related_url' => $this->related($event),
                'recorded_at' => $event->recorded_at->toIso8601String()];
        }

        return $data;
    }

    private function related(AuditEvent $event): ?string
    {
        $id = $event->target_id;
        [$table, $route, $params] = match ($event->target_type) {
            'support' => ['support_conversations', 'admin.support.index', ['conversation' => $id]],
            'user' => ['users', 'admin.users.index', ['q' => $event->target_label]],
            'package' => ['packages', 'admin.packages.index', ['q' => $event->target_label]],
            'payment' => ['payment_transactions', 'admin.payments.index', ['q' => $event->target_label]],
            'usage' => ['usage_records', 'admin.usage.index', ['q' => $event->target_label]],
            'job' => ['media_tasks', 'admin.jobs.index', ['q' => $event->target_label]],
            default => [null, null, []],
        };
        if ($table && ! DB::table($table)->where('id', $id)->exists()) {
            return null;
        }
        if ($route) {
            return route($route, $params, absolute: false);
        }
        $route = match ($event->target_type) {
            'method' => 'admin.settings.payments', 'general' => 'admin.settings.general', 'providers' => 'admin.settings.providers',
            'storage' => 'admin.settings.storage', 'defaults' => 'admin.settings.defaults', default => null,
        };

        return $route ? route($route, absolute: false) : null;
    }

    public function show(AuditEvent $auditEvent): JsonResponse
    {
        return response()->json($this->present($auditEvent, true))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $query = $this->query($f)->orderBy('occurred_at', $f['sort'] === 'oldest' ? 'asc' : 'desc')->orderBy('id', $f['sort'] === 'oldest' ? 'asc' : 'desc');

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Event ID', 'Date (UTC)', 'Category', 'Actor', 'Actor email', 'Action', 'Target', 'Result', 'Sensitive', 'IP (masked)'], ',', '"', '');
            foreach ($query->lazy(500) as $event) {
                $cells = ['AUD-'.str_pad((string) $event->id, 7, '0', STR_PAD_LEFT), $event->occurred_at->utc()->format('Y-m-d H:i:s'), $event->category,
                    $event->actor_name, $event->actor_email, $event->action, $event->target_label, $event->result, $event->sensitive ? 'Yes' : 'No', $event->ip_address];
                fputcsv($stream, array_map(fn ($value) => preg_match('/^[\\s\\x00-\\x20]*[=+@-]/u', (string) $value) ? "'".$value : $value, $cells), ',', '"', '');
            }
            fclose($stream);
        }, 'audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
