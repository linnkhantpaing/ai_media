<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionActionRequest;
use App\Models\CustomerPackageAssignment;
use App\Models\Package;
use App\Services\CustomerAccounts;
use App\Services\Subscriptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function index(Request $request, Subscriptions $subscriptions, CustomerAccounts $accounts): Response|JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'status' => ['nullable', Rule::in(['active', 'expiring', 'upcoming', 'expired', 'ended', 'cancelled'])],
            'dates' => ['nullable', Rule::in(['all', '7', '30', 'custom'])], 'from' => ['nullable', 'date_format:Y-m-d', 'required_if:dates,custom'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:dates,custom', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['expiring', 'newest', 'oldest'])], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', Rule::in([10, 20, 50])],
        ]);
        $query = $subscriptions->listing($filters['status'] ?? '')->with('user');
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->whereHas('user', fn (Builder $user) => $user->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
                if (preg_match('/^(?:SUB-)?0*(\d+)$/i', $search, $match)) {
                    $query->orWhereIn('user_id', CustomerPackageAssignment::whereKey((int) $match[1])->select('user_id'));
                }
            });
        }
        if (! empty($filters['package_id'])) {
            $query->where('package_id', $filters['package_id']);
        }
        $subscriptions->filterStatus($query, $filters['status'] ?? '');
        $dates = $filters['dates'] ?? 'all';
        if (in_array($dates, ['7', '30'], true)) {
            $query->where('ends_at', '>', now())->where('ends_at', '<=', now()->addDays((int) $dates));
        } elseif ($dates === 'custom') {
            $query->whereDate('ends_at', '>=', $filters['from'])->whereDate('ends_at', '<=', $filters['to']);
        }
        match ($filters['sort'] ?? 'expiring') {
            'newest' => $query->orderByDesc('id'), 'oldest' => $query->orderBy('id'),
            default => $query->orderByRaw('CASE WHEN closed_kind IS NULL AND ends_at > ? THEN 0 ELSE 1 END', [now()])->orderBy('ends_at')->orderByDesc('id'),
        };
        $page = $query->paginate((int) ($filters['per_page'] ?? 10));
        $nextPeriods = CustomerPackageAssignment::standing()->whereIn('user_id', $page->getCollection()->pluck('user_id'))
            ->whereNull('closed_kind')->where('starts_at', '>', now())->orderBy('starts_at')->orderByDesc('id')
            ->get()->groupBy('user_id')->map(fn ($periods) => $periods->first());
        $summary = [];
        foreach (['active', 'expiring', 'expired', 'upcoming'] as $status) {
            $count = $subscriptions->listing($status);
            $subscriptions->filterStatus($count, $status);
            $summary[$status] = $count->count();
        }
        $data = [
            'items' => $page->getCollection()->map(function (CustomerPackageAssignment $assignment) use ($subscriptions, $nextPeriods): array {
                $next = $nextPeriods->get($assignment->user_id);

                return [...$subscriptions->present($assignment), 'next_period' => $next && $next->id !== $assignment->id ? [
                    'id' => $next->id, 'name' => $next->effectiveSnapshot()['name'], 'starts_at' => $next->starts_at->toIso8601String(), 'ends_at' => $next->ends_at->toIso8601String(),
                ] : null];
            })->all(),
            'pagination' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'summary' => $summary, 'packages' => $accounts->packageOptions(),
            'packageFilters' => Package::where('published_version', '>', 0)->where('type', '!=', 'topup')->orderBy('name')->get(['id', 'name'])->toArray(),
            'timezone' => config('app.timezone'), 'today' => now(config('app.timezone'))->toDateString(),
            'filters' => ['q' => $search, 'package_id' => (string) ($filters['package_id'] ?? ''), 'status' => $filters['status'] ?? '', 'dates' => $dates, 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'sort' => $filters['sort'] ?? 'expiring', 'per_page' => (int) ($filters['per_page'] ?? 10)],
            'endpoints' => ['index' => route('admin.subscriptions.index', absolute: false), 'packages' => route('admin.users.package-options', absolute: false), 'users' => route('admin.users.index', absolute: false)],
        ];

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'no-store') : Inertia::render('admin/subscriptions', ['subscriptions' => $data]);
    }

    public function show(int $subscription, Subscriptions $subscriptions): JsonResponse
    {
        return response()->json($subscriptions->details($subscriptions->find($subscription)))->header('Cache-Control', 'no-store');
    }

    public function action(SubscriptionActionRequest $request, int $subscription, Subscriptions $subscriptions): JsonResponse
    {
        return response()->json($subscriptions->mutate($request->user('admin'), $subscription, $request->validated()));
    }
}
