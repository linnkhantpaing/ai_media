<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AiCostReport;
use App\Services\AiCostSettings;
use App\Services\Media\ProviderSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiCostController extends Controller
{
    public function index(Request $request, AiCostReport $report, AiCostSettings $prices): Response|JsonResponse
    {
        $filters = $request->validate(['range' => ['nullable', Rule::in(['1', '7', '30', '90', 'custom'])], 'from' => ['required_if:range,custom', 'nullable', 'date'],
            'to' => ['required_if:range,custom', 'nullable', 'date', 'after_or_equal:from'], 'q' => ['nullable', 'string', 'max:100'],
            'customers_page' => ['nullable', 'integer', 'min:1'], 'videos_page' => ['nullable', 'integer', 'min:1']]);
        $filters['range'] ??= '7';
        if ($filters['range'] === 'custom' && CarbonImmutable::parse($filters['from'])->diffInDays(CarbonImmutable::parse($filters['to'])) > 366) {
            abort(422, 'Choose a range of one year or less.');
        }
        $data = $report->build($filters);
        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return Inertia::render('admin/ai-costs', ['report' => $data, 'filters' => $filters, 'pricing' => $this->pricing($prices),
            'endpoints' => ['index' => route('admin.costs.index', absolute: false), 'settings' => route('admin.costs.settings', absolute: false), 'project' => route('admin.costs.project', ['project' => 'PROJECT_ID'], false)]]);
    }

    public function project(string $project, AiCostReport $report): JsonResponse
    {
        return response()->json($report->project($project));
    }

    public function saveSettings(Request $request, AiCostSettings $prices): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'models' => ['present', 'array', 'max:50'], 'models.*' => ['array'],
            'models.*.*' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'storage_gb_month' => ['required', 'numeric', 'min:0', 'max:1000'],
            'usd_mmk' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'thb_mmk' => ['nullable', 'numeric', 'min:0', 'max:1000000']]);
        $prices->save($request->user('admin'), $data);

        return response()->json($this->pricing($prices));
    }

    /** Current prices plus every model the system uses or has used, so each can be priced. */
    private function pricing(AiCostSettings $prices): array
    {
        $current = $prices->current();
        $configured = app(ProviderSettings::class)->resolved();
        $models = collect([...($configured['text_models'] ?? []), $configured['speech_model'] ?? null, ...array_keys($current['models'])])
            ->merge(DB::table('ai_usage_events')->distinct()->pluck('model'))->merge(DB::table('ai_usage_daily')->distinct()->pluck('model'))
            ->filter(fn ($model) => is_string($model) && $model !== '')->unique()->values();

        return [...$current, 'known_models' => $models->all(), 'speech_model' => $configured['speech_model'] ?? null];
    }
}
