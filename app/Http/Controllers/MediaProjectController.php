<?php

namespace App\Http\Controllers;

use App\Exceptions\MediaFailure;
use App\Http\Requests\ImportYouTubeVideoRequest;
use App\Http\Requests\InitializeMediaProjectRequest;
use App\Http\Requests\UpdateMediaScriptRequest;
use App\Http\Requests\UpdateMediaSettingsRequest;
use App\Jobs\ImportYouTubeVideo;
use App\Jobs\InspectMediaProject;
use App\Jobs\ProcessMediaRun;
use App\Models\MediaPreset;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Services\Media\MediaNarrationFit;
use App\Services\Media\MediaRunActions;
use App\Services\Media\MediaRuntime;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaUploads;
use App\Services\Media\ProjectLibrary;
use App\Services\Media\ProviderSettings;
use App\Services\Media\StorageSettings;
use App\Services\Media\YouTubeImports;
use App\Services\PackageCatalog;
use App\Services\StudioPreferences;
use App\Services\UsageLedger;
use App\Support\MediaSettings;
use App\Support\StudioTesting;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaProjectController extends Controller
{
    public function __construct(private MediaUploads $uploads, private MediaRuntime $runtime) {}

    public function index(Request $request, ProjectLibrary $library): Response
    {
        $result = $library->browse($request);
        $frames = ['draft' => 'drafts', 'awaiting_review' => 'awaitingReview', 'processing' => 'processing', 'completed' => 'completed', 'needs_attention' => 'needsAttention'];
        $result['projects']->through(fn (MediaProject $project): array => $library->present($project, $this->data($project)));

        return Inertia::render('projects/index', [
            'frame' => $frames[$result['filters']['status']] ?? 'allProjects', ...$result, ...$this->common(),
        ]);
    }

    public function reopen(Request $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        $run = DB::transaction(function () use ($project): MediaRun {
            $project = MediaProject::lockForUpdate()->findOrFail($project->id);
            if ($project->busy() || ($project->lines ?? []) === []) {
                $this->error('project_busy', 409);
            }
            $run = $project->runs()->create([
                'provider_revision_id' => app(ProviderSettings::class)->snapshot(),
                'phase' => 'analysis', 'status' => 'awaiting_review', 'stage' => 'translate',
                'progress' => 100, 'settings' => $project->settings, 'lines' => $project->lines,
                'stages' => array_fill_keys(['prepare', 'transcribe', 'context', 'translate'], 'completed'), 'artifacts' => [],
            ]);
            $project->forceFill(['active_run_id' => $run->id, 'status' => 'awaiting_review', 'error_code' => null])->save();

            return $run;
        });

        return response()->json(['project' => $this->data($project->fresh())]);
    }

    public function create(Request $request): Response
    {
        $recent = MediaProject::where('user_id', $request->user()->id)->latest('updated_at')->limit(8)->get();
        $presetId = $request->query('preset');
        $preset = is_string($presetId) && ctype_digit($presetId) ? MediaPreset::where('user_id', $request->user()->id)->find((int) $presetId) : null;

        return Inertia::render('projects/create', [
            'frame' => 'newProject', 'initialProject' => null,
            'recentProjects' => $recent->map(fn (MediaProject $project) => $this->data($project)),
            ...$this->common($preset),
            'startPreset' => $preset ? ['id' => $preset->id, 'name' => $preset->name] : null,
        ]);
    }

    public function initialize(InitializeMediaProjectRequest $request): JsonResponse
    {
        $this->requireFeature('local');
        try {
            $project = $this->uploads->initialize($request->user()->id, $request->validated());
        } catch (MediaFailure $failure) {
            $this->error($failure->reason, $failure->reason === 'upload_conflict' ? 409 : 422);
        }

        return response()->json(['project' => $this->data($project)], 201);
    }

    public function importYouTube(ImportYouTubeVideoRequest $request, YouTubeImports $imports): JsonResponse
    {
        $this->requireFeature('youtube');
        try {
            $project = $imports->initialize($request->user()->id, $request->validated());
        } catch (MediaFailure $failure) {
            $this->error($failure->reason, $failure->reason === 'upload_conflict' ? 409 : 422);
        }
        if ($project->wasRecentlyCreated) {
            ImportYouTubeVideo::dispatch($project->id)->afterCommit();
        }

        return response()->json(['project' => $this->data($project)], 202);
    }

    public function retryImport(Request $request, string $mediaProject, YouTubeImports $imports): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        try {
            $imports->retry($project);
        } catch (MediaFailure $failure) {
            $this->error($failure->reason, $failure->reason === 'project_busy' ? 409 : 422);
        }

        return response()->json(['project' => $this->data($project->fresh())], 202);
    }

    public function cancelImport(Request $request, string $mediaProject, YouTubeImports $imports): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        try {
            $imports->cancel($project);
        } catch (MediaFailure $failure) {
            $this->error($failure->reason, 409);
        }

        return response()->json(['project' => $this->data($project->fresh())]);
    }

    public function status(Request $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);

        return response()->json(['project' => $this->data($project), 'runtime' => $this->runtime->capabilities()]);
    }

    public function upload(Request $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        $offset = $request->header('Upload-Offset');
        if (! is_string($offset) || ! ctype_digit($offset) || (int) $request->header('Content-Length', 0) < 1 || (int) $request->header('Content-Length', 0) > config('media.chunk_bytes')) {
            $this->error('invalid_chunk', 422);
        }
        $stream = $request->getContent(true);
        $bytes = stream_get_contents($stream, config('media.chunk_bytes') + 1);
        fclose($stream);
        if (! is_string($bytes) || strlen($bytes) !== (int) $request->header('Content-Length')) {
            $this->error('invalid_chunk', 422);
        }
        try {
            $next = $this->uploads->append($project, (int) $offset, $bytes);
        } catch (MediaFailure $failure) {
            return response()->json(['code' => $failure->reason, 'offset' => $this->uploads->offset($project->fresh())], $failure->reason === 'upload_conflict' ? 409 : 422);
        }

        return response()->json(['offset' => $next]);
    }

    public function complete(Request $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        Cache::lock('media-upload:'.$project->id, 30)->block(5, function () use ($project): void {
            $project->refresh();
            if (in_array($project->status, ['validating', 'ready'], true)) {
                return;
            }
            if (! in_array($project->status, ['uploading', 'invalid'], true) || $this->uploads->offset($project) !== $project->expected_bytes) {
                $this->error('upload_incomplete', 409);
            }
            $project->forceFill(['uploaded_bytes' => $project->expected_bytes, 'status' => 'validating', 'error_code' => null])->save();
            InspectMediaProject::dispatch($project->id)->afterCommit();
        });

        return response()->json(['project' => $this->data($project->fresh())]);
    }

    public function update(UpdateMediaSettingsRequest $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        $data = $request->validated();
        DB::transaction(function () use ($project, $data): void {
            $locked = MediaProject::lockForUpdate()->findOrFail($project->id);
            if ($locked->busy() && $locked->status !== 'validating') {
                $this->error('project_busy', 409);
            }
            $changes = ['title' => $data['title']];
            if (isset($data['settings'])) {
                $settings = MediaSettings::normalize($data['settings']);
                if (($locked->lines ?? []) !== [] && array_intersect_key($settings, array_flip(['workflow', 'source_lang', 'narration_lang', 'subtitle_lang', 'target_seconds', 'narration_style', 'story_order', 'spoilers', 'custom_style', 'character_focus', 'instructions'])) != array_intersect_key(MediaSettings::normalize($locked->settings), array_flip(['workflow', 'source_lang', 'narration_lang', 'subtitle_lang', 'target_seconds', 'narration_style', 'story_order', 'spoilers', 'custom_style', 'character_focus', 'instructions']))) {
                    if (! ($data['confirm_reanalysis'] ?? false)) {
                        $this->error('confirm_reanalysis', 409);
                    }
                    $changes['lines'] = [];
                    $changes['status'] = 'ready';
                    $changes['active_run_id'] = null;
                }
                $changes['settings'] = $settings;
            }
            $locked->forceFill($changes)->save();
        });

        return response()->json(['project' => $this->data($project->fresh())]);
    }

    public function setup(Request $request, string $mediaProject): Response|RedirectResponse
    {
        $project = $this->owned($request, $mediaProject);
        if ($project->status === 'uploading' || $project->status === 'validating' || ! ($project->metadata['audio'] ?? false)) {
            return redirect()->route('media.upload.page', $project->id);
        }

        return Inertia::render('projects/setup', ['frame' => 'projectSetup', 'initialProject' => $this->data($project), ...$this->common()]);
    }

    public function uploadPage(Request $request, string $mediaProject): Response
    {
        $project = $this->owned($request, $mediaProject);

        return Inertia::render('projects/create', ['frame' => 'newProject', 'initialProject' => $this->data($project), 'recentProjects' => [], ...$this->common()]);
    }

    public function analysis(Request $request, string $mediaProject): Response|RedirectResponse
    {
        $project = $this->owned($request, $mediaProject);
        if ($project->source_expired_at) {
            $this->error('media_expired', 422);
        }
        if (! ($project->metadata['audio'] ?? false)) {
            return redirect()->route('media.upload.page', $project->id);
        }

        return Inertia::render('projects/analyze', ['frame' => 'projectProcessing', 'initialProject' => $this->data($project), ...$this->common()]);
    }

    public function start(Request $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        $this->preflight($project);
        $run = DB::transaction(function () use ($project): MediaRun {
            $project = MediaProject::lockForUpdate()->findOrFail($project->id);
            if ($project->busy()) {
                $this->error('project_busy', 409);
            }
            $run = $project->runs()->create([
                'provider_revision_id' => app(ProviderSettings::class)->snapshot(),
                'phase' => 'analysis', 'status' => 'queued', 'stage' => 'preparing',
                'settings' => $project->settings, 'lines' => $project->lines ?? [], 'stages' => [], 'artifacts' => [],
            ]);
            app(UsageLedger::class)->reserve($run);
            $project->forceFill(['active_run_id' => $run->id, 'status' => 'queued', 'error_code' => null])->save();

            return $run;
        });
        ProcessMediaRun::dispatch($run->id)->afterCommit();

        return response()->json(['project' => $this->data($project->fresh())], 202);
    }

    public function script(UpdateMediaScriptRequest $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        DB::transaction(function () use ($project, $request): void {
            $project = MediaProject::lockForUpdate()->findOrFail($project->id);
            if ($project->busy() || $project->status !== 'awaiting_review' || ! $project->active_run_id) {
                $this->error('project_busy', 409);
            }
            $lines = $project->lines ?? [];
            $updates = collect($request->validated('lines'))->keyBy('index');
            if (count($lines) !== $updates->count() || array_keys($lines) !== $updates->keys()->sort()->values()->all()) {
                $this->error('invalid_script', 422);
            }
            foreach ($lines as $index => &$line) {
                $text = trim($updates[$index]['text']);
                if ($text === '') {
                    $this->error('invalid_script', 422);
                }
                $line['text'] = $text;
            }
            unset($line);
            $project->forceFill(['lines' => $lines])->save();
            MediaRun::whereKey($project->active_run_id)->update(['lines' => json_encode($lines, JSON_THROW_ON_ERROR)]);
        });

        return response()->json(['project' => $this->data($project->fresh())]);
    }

    public function generate(Request $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        $this->preflight($project);
        DB::transaction(function () use ($project): void {
            $project = MediaProject::lockForUpdate()->findOrFail($project->id);
            if ($project->busy() || $project->status !== 'awaiting_review' || ! $project->active_run_id || ($project->lines ?? []) === []) {
                $this->error('project_busy', 409);
            }
            $run = MediaRun::lockForUpdate()->findOrFail($project->active_run_id);
            if ($run->status !== 'awaiting_review') {
                $this->error('project_busy', 409);
            }
            app(MediaNarrationFit::class)->requireReady($project);
            $run->forceFill(['provider_revision_id' => app(ProviderSettings::class)->snapshot(), 'phase' => 'generate', 'status' => 'queued', 'settings' => $project->settings, 'stage' => 'rendering', 'progress' => 0, 'lines' => $project->lines, 'error_code' => null])->save();
            app(UsageLedger::class)->reserve($run);
            $project->forceFill(['status' => 'queued', 'error_code' => null])->save();
        });
        ProcessMediaRun::dispatch($project->active_run_id)->afterCommit();

        return response()->json(['project' => $this->data($project->fresh())], 202);
    }

    public function retry(Request $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        try {
            app(MediaRunActions::class)->retry($project);
        } catch (MediaFailure $failure) {
            $this->error($failure->reason, $failure->reason === 'project_busy' ? 409 : 503);
        }

        return response()->json(['project' => $this->data($project->fresh())], 202);
    }

    public function cancel(Request $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        try {
            app(MediaRunActions::class)->cancel($project);
        } catch (MediaFailure $failure) {
            $this->error($failure->reason, 409);
        }

        return response()->json(['project' => $this->data($project->fresh())]);
    }

    public function destroy(Request $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        Cache::lock('media-upload:'.$project->id, 30)->block(5, function () use ($project, $request): void {
            DB::transaction(function () use ($project, $request): void {
                $project = MediaProject::lockForUpdate()->findOrFail($project->id);
                if ($project->busy() || ((($project->lines ?? []) !== [] || $project->output_path) && ! $request->boolean('confirm'))) {
                    $this->error('project_busy', 409);
                }
                try {
                    app(MediaStorage::class)->discard($project);
                } catch (MediaFailure $failure) {
                    $this->error($failure->reason, 503);
                }
                $project->delete();
            });
        });

        return response()->json(['removed' => true]);
    }

    public function file(Request $request, string $mediaProject, string $kind): BinaryFileResponse
    {
        $project = $this->owned($request, $mediaProject);
        $path = match ($kind) {
            'source' => ($project->metadata['audio'] ?? false) ? $project->source_path : null,
            'poster' => ($project->metadata['poster'] ?? false) ? 'media/'.$project->id.'/poster.jpg' : null,
            'video' => $project->output_path,
            'audio' => $project->audio_path,
            'subtitles' => $project->subtitle_path,
            'styledSubtitles' => ($project->metadata['output']['styled'] ?? false) ? preg_replace('/\.srt$/', '.ass', (string) $project->subtitle_path) : null,
            default => null,
        };
        abort_unless($path, 404);
        try {
            $file = app(MediaStorage::class)->materialize($project, $path);
        } catch (MediaFailure) {
            abort(404);
        }
        if (in_array($kind, ['subtitles', 'styledSubtitles'], true) || $request->boolean('download')) {
            return response()->download($file, (Str::slug($project->title) ?: 'ai-media-recap').($kind === 'styledSubtitles' ? '.ass' : ($kind === 'subtitles' ? '.srt' : ($kind === 'audio' ? '.'.pathinfo($path, PATHINFO_EXTENSION) : '.mp4'))));
        }

        return response()->file($file, ['Content-Type' => $kind === 'poster' ? 'image/jpeg' : ($kind === 'source' ? $project->source_mime : ($kind === 'audio' ? (str_ends_with($path, '.mp3') ? 'audio/mpeg' : 'audio/wav') : 'video/mp4'))]);
    }

    public function legacy(Request $request): RedirectResponse
    {
        $project = MediaProject::where('user_id', $request->user()->id)->whereNotNull('metadata')->latest('updated_at')->first();

        return $project ? redirect()->route(in_array($request->route('step'), ['setup'], true) ? 'media.setup' : 'media.analysis', $project->id) : redirect()->route('projects.create');
    }

    private function owned(Request $request, string $id): MediaProject
    {
        return MediaProject::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function preflight(MediaProject $project): void
    {
        if (! ($project->metadata['audio'] ?? false)) {
            $this->error('source_not_ready', 422);
        }
        if (! $this->runtime->available()) {
            $this->error('media_tools_missing', 503);
        }
        if (! app(ProviderSettings::class)->ready()) {
            $this->error('ai_key_missing', 503);
        }
    }

    private function common(?MediaPreset $preset = null): array
    {
        $assignment = request()->user()?->currentPackageAssignment;
        $definition = StudioTesting::enabled() ? [] : ($assignment?->effectiveSnapshot()['definition'] ?? []);
        $limits = app(StorageSettings::class)->limits();

        return [
            'testingMode' => StudioTesting::enabled(),
            'features' => $definition === [] ? null : PackageCatalog::features($definition),
            // Per-workflow access (included / locked / hidden), limits and remaining minutes from the Admin plan.
            'workflows' => $definition === [] ? null : app(UsageLedger::class)->workflowBalances((int) request()->user()->id, $assignment),
            'watermark' => (bool) ($definition['watermark'] ?? false),
            'planActive' => app(UsageLedger::class)->hasProcessingAccess(request()->user()),
            'billingUrl' => route('account.billing', absolute: false),
            'studioDefaults' => MediaSettings::defaults((int) request()->user()->id),
            'personalDefaults' => app(StudioPreferences::class)->overrides(request()->user(), $preset),
            'dictionaryUrl' => route('media.dictionary.save', absolute: false),
            'runtime' => $this->runtime->capabilities(),
            'presetsUrl' => route('media.presets', absolute: false),
            'storage' => ['disk' => app(StorageSettings::class)->connection()['disk'], 'ready' => app(MediaStorage::class)->ready(), 'used' => app(MediaStorage::class)->reservedBytes(), 'limit' => app(StorageSettings::class)->policy()['budget']],
            'limits' => ['bytes' => min($limits['max_bytes'], $definition['max_upload_bytes'] ?? $limits['max_bytes']), 'duration' => min($limits['max_duration'], $definition['max_video_seconds'] ?? $limits['max_duration']), 'chunkBytes' => config('media.chunk_bytes')],
            'initializeUrl' => route('media.initialize', absolute: false),
            'youtubeImportUrl' => route('media.youtube', absolute: false),
            'resumeUrlTemplate' => route('media.status', ['mediaProject' => 'PROJECT_ID'], absolute: false),
        ];
    }

    private function data(MediaProject $project): array
    {
        $run = $project->activeRun;
        $preview = $project->previews()->latest()->first();
        $link = fn (string $name, array $extra = []) => route('media.'.$name, ['mediaProject' => $project->id, ...$extra], absolute: false);

        return [
            'allowance' => app(UsageLedger::class)->quote($project),
            'id' => $project->id, 'title' => $project->title, 'filename' => $project->source_name,
            'bytes' => $project->expected_bytes, 'uploadedBytes' => $this->uploads->offset($project),
            'status' => $project->status, 'errorCode' => $project->error_code, 'sourceProgress' => $project->source_progress,
            'metadata' => $project->metadata, 'settings' => MediaSettings::normalize($project->settings),
            'scriptRevision' => hash('sha256', json_encode($project->lines ?? [])),
            'narrationFitReady' => $project->lines && $project->previews()->where('kind', 'fit')->where('status', 'completed')->where('result->ready', true)->where('result->signature', MediaNarrationFit::signature($project, $project->lines, $project->settings))->exists(),
            'lines' => $project->lines ?? [], 'updatedAt' => $project->updated_at->toIso8601String(),
            'storageDisk' => $project->storage_disk, 'fingerprint' => $project->fingerprint,
            'sourceType' => $project->source_type, 'sourceUrl' => $project->source_url,
            'import' => $project->source_type === 'youtube' ? ['stage' => $project->import_stage, 'downloadedBytes' => $project->imported_bytes, 'cancelRequested' => $project->import_cancel_requested] : null,
            'hasOutput' => (bool) $project->output_path, 'hasAudio' => (bool) $project->audio_path, 'previewBusy' => $preview && in_array($preview->status, ['queued', 'running'], true), 'latestPreview' => $preview ? app(MediaToolsController::class)->data($preview) : null, 'hasSubtitles' => (bool) $project->subtitle_path,
            'run' => $run ? ['id' => $run->id, 'status' => $run->status, 'phase' => $run->phase, 'stage' => $run->stage, 'progress' => $run->progress, 'stageProgress' => $run->artifacts['stage_progress'] ?? 0, 'stages' => $run->stages ?? [], 'errorCode' => $run->error_code, 'cancelRequested' => $run->cancel_requested] : null,
            'links' => [
                'status' => $link('status'), 'upload' => $link('upload'), 'complete' => $link('complete'),
                'update' => $link('update'), 'destroy' => $link('destroy'),
                'uploadPage' => $link('upload.page'), 'setup' => $link('setup'), 'analysis' => $link('analysis'),
                'start' => $link('start'), 'generate' => $link('generate'), 'scenes' => $link('scenes'), 'styledSubtitles' => $link('file', ['kind' => 'styledSubtitles']), 'script' => $link('script'),
                'importRetry' => $link('import.retry'), 'importCancel' => $link('import.cancel'),
                'retry' => $link('retry'), 'cancel' => $link('cancel'), 'reopen' => $link('reopen'),
                'source' => $link('file', ['kind' => 'source']), 'poster' => $link('file', ['kind' => 'poster']),
                'previews' => $link('preview.create'), 'audio' => $link('file', ['kind' => 'audio']), 'video' => $link('file', ['kind' => 'video']), 'subtitles' => $link('file', ['kind' => 'subtitles']),
            ],
        ];
    }

    /** Reject starting a source type the customer's current package does not include. */
    private function requireFeature(string $feature): void
    {
        if (StudioTesting::enabled()) {
            return;
        }
        $assignment = request()->user()->currentPackageAssignment;
        if ($assignment && ! PackageCatalog::features($assignment->effectiveSnapshot()['definition'] ?? [])[$feature]) {
            $this->error('feature_unavailable', 403);
        }
    }

    private function error(string $code, int $status): never
    {
        throw new HttpResponseException(response()->json(['code' => $code], $status));
    }
}
