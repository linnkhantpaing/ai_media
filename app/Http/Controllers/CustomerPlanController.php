<?php

namespace App\Http\Controllers;

use App\Models\MediaRun;
use App\Models\UsageRecord;
use App\Services\CustomerPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPlanController extends Controller
{
    public function index(Request $request, CustomerPlan $plan): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', Rule::in(['subs', 'movie', 'translate'])],
            'period' => ['nullable', Rule::in(['current', 'all'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $filters = ['q' => trim($filters['q'] ?? ''), 'type' => $filters['type'] ?? '', 'period' => $filters['period'] ?? 'current'];
        $exports = $plan->exports($request->user(), $filters)->paginate(10)->withQueryString();
        $records = UsageRecord::where('user_id', $request->user()->id)->where('kind', 'processing')->whereIn('media_run_id', $exports->getCollection()->pluck('id'))->get()->keyBy('media_run_id');
        $exports->through(fn (MediaRun $run) => $plan->export($run, $records->get($run->id)));

        return Inertia::render('account/plan', ['overview' => $plan->overview($request->user()),
            'exports' => $exports, 'filters' => $filters, 'availablePlans' => $plan->plans()]);
    }

    public function show(Request $request, string $run, CustomerPlan $plan): JsonResponse
    {
        $export = MediaRun::whereHas('project', fn ($q) => $q->where('user_id', $request->user()->id))
            ->where('phase', 'generate')->where('status', 'completed')->with('project')->findOrFail($run);
        $record = UsageRecord::where('user_id', $request->user()->id)->where('media_run_id', $export->id)->where('kind', 'processing')->first();
        $events = $record?->events()->whereIn('kind', ['reserved', 'charged', 'released', 'adjusted', 'returned'])->orderBy('id')->limit(100)->get(['kind', 'created_at'])
            ->map(fn ($event) => ['type' => $event->kind, 'at' => $event->created_at->toIso8601String()])->all() ?? [];

        return response()->json(['export' => $plan->export($export, $record),
            'package' => $record ? array_intersect_key($record->package_snapshot ?? [], array_flip(['name', 'version'])) : null,
            'period_start' => $record?->period_start?->toIso8601String(), 'period_end' => $record?->period_end?->toIso8601String(),
            'events' => $events])->header('Cache-Control', 'private, no-store');
    }
}
