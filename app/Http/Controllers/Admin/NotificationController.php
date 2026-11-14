<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    private function filters(Request $request): array
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', Rule::in(['payments', 'jobs', 'subscriptions', 'system', 'support'])],
            'read' => ['nullable', Rule::in(['read', 'unread'])], 'priority' => ['nullable', Rule::in(['info', 'warning', 'critical'])],
            'issue' => ['nullable', Rule::in(['attention', 'resolved', 'info'])], 'archived' => ['nullable', 'boolean'],
            'dates' => ['nullable', Rule::in(['1', '7', '30', 'all', 'custom'])],
            'from' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'priority'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['nullable', Rule::in([10, 25, 50])],
        ]);

        return array_replace(['q' => '', 'category' => '', 'read' => '', 'priority' => '', 'issue' => '', 'archived' => false,
            'dates' => '7', 'from' => '', 'to' => '', 'sort' => 'newest', 'page' => 1, 'per_page' => 10], array_filter($input, fn ($v) => $v !== null));
    }

    private function feedData(AdminNotifications $service, User $admin): array
    {
        return ['summary' => $service->summary($admin), 'items' => $service->rows($service->inbox($admin, ['read' => 'unread']))->orderByDesc('n.received_at')->orderByDesc('n.id')->limit(5)->get()->map(fn ($row) => $service->present($row))->all()];
    }

    public function index(Request $request, AdminNotifications $service): Response|JsonResponse
    {
        $f = $this->filters($request);
        $service->synchronize();
        $admin = $request->user('admin');
        $query = $service->inbox($admin, $f);
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / (int) $f['per_page']));
        $pageNumber = min((int) $f['page'], $pages);
        $list = $service->rows(clone $query);
        if ($f['sort'] === 'priority') {
            $list->orderByRaw("CASE n.priority WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END");
        }
        $list->orderBy('n.received_at', $f['sort'] === 'oldest' ? 'asc' : 'desc')->orderBy('n.id', $f['sort'] === 'oldest' ? 'asc' : 'desc');
        $items = $list->offset(($pageNumber - 1) * (int) $f['per_page'])->limit((int) $f['per_page'])->get()->map(fn ($row) => $service->present($row))->all();
        $tabFilters = [...$f, 'category' => ''];
        $tabs = $service->inbox($admin, $tabFilters)->selectRaw('n.category, COUNT(*) AS total')->groupBy('n.category')->pluck('total', 'category')->map(fn ($n) => (int) $n)->all();
        $data = ['items' => $items, 'filters' => $f, 'summary' => $service->summary($admin), 'tabs' => $tabs, 'today' => now()->toDateString(),
            'pagination' => ['page' => $pageNumber, 'pages' => $pages, 'total' => $total, 'from' => $total ? ($pageNumber - 1) * (int) $f['per_page'] + 1 : null, 'to' => $total ? ($pageNumber - 1) * (int) $f['per_page'] + count($items) : null],
            'endpoints' => ['index' => route('admin.notifications.index', absolute: false)]];

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'private, no-store') : Inertia::render('admin/notifications', ['notifications' => $data]);
    }

    public function feed(Request $request, AdminNotifications $service): JsonResponse
    {
        $service->synchronize();

        return response()->json($this->feedData($service, $request->user('admin')))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $notice, AdminNotifications $service): JsonResponse
    {
        $service->synchronize();

        return response()->json($service->detail($request->user('admin'), $notice))->header('Cache-Control', 'private, no-store');
    }

    public function action(Request $request, AdminNotifications $service): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['read', 'unread', 'archive', 'restore', 'read_all'])],
            'ids' => ['required_unless:action,read_all', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'min:1', 'distinct']]);
        $service->change($request->user('admin'), $data['action'], $data['ids'] ?? []);

        return response()->json($this->feedData($service, $request->user('admin')))->header('Cache-Control', 'private, no-store');
    }
}
