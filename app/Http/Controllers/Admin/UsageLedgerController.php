<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustUsageRequest;
use App\Models\CustomerPackageAssignment;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\Package;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\CustomerUsageSummary;
use App\Services\Media\MediaStorage;
use App\Services\UsageLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsageLedgerController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate(['q' => ['nullable', 'string', 'max:100'], 'package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'kind' => ['nullable', Rule::in(['processing', 'storage'])], 'status' => ['nullable', Rule::in(['reserved', 'charged', 'released', 'adjusted', 'stored'])],
            'period' => ['nullable', Rule::in(['current', 'all'])], 'dates' => ['nullable', Rule::in(['all', '1', '7', '30', 'custom'])], 'from' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:dates,custom', 'date_format:Y-m-d', 'after_or_equal:from'], 'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', Rule::in([10, 20, 50])]]);
    }

    private function query(array $f, bool $dates = true): Builder
    {
        $customers = app(CustomerUsageSummary::class);

        return $customers->records([...$f, 'q' => '', 'package_id' => ''], $dates)->whereIn('user_id', $customers->customers($f)->select('users.id'));
    }

    public function index(Request $request, CustomerUsageSummary $customers): Response|JsonResponse
    {
        $f = $this->filters($request);
        $base = $customers->customers($f);
        $summary = $customers->summary(clone $base, $f);
        $page = $base->with('currentPackageAssignment')->orderBy('id', ($f['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')
            ->paginate((int) ($f['per_page'] ?? 10));
        $data = ['items' => $page->getCollection()->map(fn (User $user) => $customers->present($user, $f))->all(),
            'summary' => $summary,
            'today' => now()->toDateString(), 'packages' => Package::orderBy('name')->get(['id', 'name'])->toArray(), 'timezone' => config('app.timezone', 'UTC'),
            'pagination' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'filters' => ['q' => $f['q'] ?? '', 'package_id' => (string) ($f['package_id'] ?? ''), 'kind' => $f['kind'] ?? '', 'status' => $f['status'] ?? '', 'period' => $f['period'] ?? 'current',
                'dates' => $f['dates'] ?? '30', 'from' => $f['from'] ?? '', 'to' => $f['to'] ?? '', 'sort' => $f['sort'] ?? 'newest', 'per_page' => (int) ($f['per_page'] ?? 10)],
            'endpoints' => ['index' => route('admin.usage.index', absolute: false), 'export' => route('admin.usage.export', absolute: false)]];

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'no-store') : Inertia::render('admin/usage-ledger', ['ledger' => $data]);
    }

    public function customer(Request $request, User $user, CustomerUsageSummary $customers): JsonResponse
    {
        abort_if($user->is_admin, 404);
        $f = $this->filters($request);
        $request->validate(['records_page' => ['nullable', 'integer', 'min:1'], 'recent_page' => ['nullable', 'integer', 'min:1']]);
        $f['q'] = '';
        $f['package_id'] = '';
        $records = $customers->records($f)->where('user_id', $user->id)->latest('id')
            ->paginate(10, ['*'], 'records_page', $request->integer('records_page', 1));
        $videos = $customers->recent($user->id, $f)->latest('updated_at')->latest('id')
            ->paginate(12, ['*'], 'recent_page', $request->integer('recent_page', 1));

        return response()->json([
            'customer' => $customers->present($user, $f),
            'records' => $records->getCollection()->map(fn (UsageRecord $record) => $this->present($record))->all(),
            'recent' => $videos->getCollection()->map(fn (UsageRecord $record) => $customers->video($record))->all(),
            'records_pagination' => ['page' => $records->currentPage(), 'pages' => $records->lastPage(), 'total' => $records->total()],
            'recent_pagination' => ['page' => $videos->currentPage(), 'pages' => $videos->lastPage(), 'total' => $videos->total()],
        ])->header('Cache-Control', 'no-store');
    }

    public function video(UsageRecord $usage): BinaryFileResponse
    {
        $project = MediaProject::findOrFail($usage->media_project_id);
        abort_unless($usage->user_id === $project->user_id && $usage->kind === 'processing'
            && in_array($usage->status, ['charged', 'adjusted'], true) && $project->output_path
            && MediaRun::whereKey($usage->media_run_id)->where('media_project_id', $project->id)->where('status', 'completed')->exists(), 404);
        try {
            $path = app(MediaStorage::class)->materialize($project, $project->output_path);
        } catch (\Throwable) {
            abort(404);
        }

        return response()->file($path, ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, no-store']);
    }

    private function present(UsageRecord $r): array
    {
        $project = MediaProject::find($r->media_project_id);
        $released = $r->status === 'released' && $r->kind === 'processing' ? ($r->events()->where('kind', 'released')->latest('id')->first()?->changes ?? []) : [];

        return ['id' => $r->id, 'code' => $r->code(), 'kind' => $r->kind, 'status' => $r->status, 'revision' => $r->revision, 'customer' => $r->customer_snapshot, 'project' => $r->project_snapshot,
            'package' => $r->package_snapshot, 'period_start' => $r->period_start?->toIso8601String(), 'period_end' => $r->period_end?->toIso8601String(),
            'released_seconds' => (int) ($released['seconds'] ?? 0), 'released_videos' => (int) ($released['videos'] ?? 0), 'reserved_seconds' => $r->reserved_seconds, 'charged_seconds' => $r->charged_seconds, 'returned_seconds' => $r->returned_seconds, 'reserved_videos' => $r->reserved_videos, 'charged_videos' => $r->charged_videos, 'returned_videos' => $r->returned_videos, 'storage_bytes' => $r->storage_bytes,
            'created_at' => $r->created_at->toIso8601String(), 'updated_at' => $r->updated_at->toIso8601String(), 'run_id' => $r->media_run_id,
            'thumbnail' => $project && ($project->metadata['poster'] ?? false) ? route('admin.usage.poster', $r->id, absolute: false) : null,
            'detail_url' => route('admin.usage.show', $r->id, absolute: false), 'adjust_url' => route('admin.usage.adjust', $r->id, absolute: false),
            'can_adjust' => $r->kind === 'processing' && in_array($r->status, ['charged', 'adjusted'], true) && ($r->charged_seconds > $r->returned_seconds || $r->charged_videos > $r->returned_videos)];
    }

    public function show(UsageRecord $usage, UsageLedger $ledger): JsonResponse
    {
        $assignment = CustomerPackageAssignment::find($usage->assignment_id);
        $current = CustomerPackageAssignment::current()->where('user_id', $usage->user_id)->first();
        $run = MediaRun::find($usage->media_run_id);
        $files = DB::table('media_files')->where('media_project_id', $usage->media_project_id)->whereNotNull('verified_at')->get()
            ->map(fn ($f) => ['name' => basename($f->path), 'bytes' => (int) $f->bytes, 'state' => $f->state, 'category' => $f->is_source ? 'source' : pathinfo($f->path, PATHINFO_EXTENSION)])->all();

        return response()->json([...$this->present($usage), 'balances' => $ledger->balances($usage->user_id, $usage->allowance_start),
            'definition' => $usage->package_snapshot['definition'] ?? null, 'current_period' => $current ? ['package' => $current->effectiveSnapshot()['name'], 'starts_at' => $current->starts_at->toIso8601String(), 'ends_at' => $current->ends_at->toIso8601String(), 'status' => $current->status()] : null,
            'runtime_seconds' => $run?->started_at && $run?->finished_at ? (int) $run->started_at->diffInSeconds($run->finished_at) : null,
            'files' => $files, 'events' => $usage->events()->orderBy('id')->get(['id', 'kind', 'changes', 'actor_name', 'reason', 'created_at'])->toArray(),
            'period_status' => $assignment?->status() ?? 'unmetered'])->header('Cache-Control', 'no-store');
    }

    public function adjust(AdjustUsageRequest $request, UsageRecord $usage, UsageLedger $ledger): JsonResponse
    {
        $record = $ledger->adjust($request->user('admin'), $usage, $request->validated());

        return response()->json(['record' => $this->present($record)])->header('Cache-Control', 'no-store');
    }

    public function export(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $query = $this->query($f)->orderBy('id');
        abort_if((clone $query)->count() > 10000, 422, 'Narrow the filters to export up to 10,000 records.');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Usage ID', 'Customer', 'Email', 'Project', 'Package', 'Kind', 'Status', 'Period start (UTC)', 'Period end (UTC)', 'Charged seconds', 'Returned seconds', 'Reserved seconds', 'Videos counted', 'Reserved videos', 'Storage bytes', 'Recorded (UTC)'], ',', '"', '');
            foreach ($query->cursor() as $r) {
                $row = [$r->code(), $r->customer_snapshot['name'] ?? '', $r->customer_snapshot['email'] ?? '', $r->project_snapshot['title'] ?? '', $r->package_snapshot['name'] ?? 'Unmetered', $r->kind, $r->status, $r->period_start?->toDateTimeString(), $r->period_end?->toDateTimeString(), $r->charged_seconds, $r->returned_seconds, $r->reserved_seconds, $r->charged_videos - $r->returned_videos, $r->reserved_videos, $r->storage_bytes, $r->updated_at->toDateTimeString()];
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[\\s]*[=+@-]/u', $v) ? "'".$v : $v, $row), ',', '"', '');
            }fclose($out);
        }, 'usage-ledger-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function poster(UsageRecord $usage): BinaryFileResponse
    {
        $project = MediaProject::findOrFail($usage->media_project_id);
        try {
            $path = app(MediaStorage::class)->materialize($project, 'media/'.$project->id.'/poster.jpg');
        } catch (\Throwable) {
            abort(404);
        }

        return response()->file($path, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=60']);
    }
}
