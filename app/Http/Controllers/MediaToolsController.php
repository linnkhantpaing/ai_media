<?php

namespace App\Http\Controllers;

use App\Exceptions\MediaFailure;
use App\Http\Requests\UpdateMediaSettingsRequest;
use App\Jobs\PrepareMediaPreview;
use App\Models\MediaPreset;
use App\Models\MediaPreview;
use App\Models\MediaProject;
use App\Models\User;
use App\Services\Media\MediaNarrationFit;
use App\Services\Media\MediaRenderer;
use App\Services\Media\MediaRuntime;
use App\Services\Media\MediaStorage;
use App\Services\Media\ProviderSettings;
use App\Support\MediaSettings;
use App\Support\StudioTesting;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaToolsController extends Controller
{
    public function presets(Request $request): JsonResponse
    {
        return response()->json(['presets' => MediaPreset::where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name', 'settings'])]);
    }

    public function savePreset(UpdateMediaSettingsRequest $request): JsonResponse
    {
        $request->validate(['title' => ['required', 'string', 'max:60'], 'settings' => ['required', 'array']]);
        $preset = DB::transaction(function () use ($request): MediaPreset {
            User::lockForUpdate()->findOrFail($request->user()->id);
            $name = trim($request->validated('title'));
            $query = MediaPreset::where('user_id', $request->user()->id);
            $preset = (clone $query)->where('name', $name)->first();
            abort_if(! $preset && $query->count() >= 12, 422);

            return MediaPreset::updateOrCreate(['user_id' => $request->user()->id, 'name' => $name], ['settings' => MediaSettings::normalize($request->validated('settings'))]);
        });

        return response()->json(['preset' => $preset->only(['id', 'name', 'settings'])], 201);
    }

    public function deletePreset(Request $request, int $preset): JsonResponse
    {
        MediaPreset::where('user_id', $request->user()->id)->findOrFail($preset)->delete();

        return response()->json(['removed' => true]);
    }

    public function createPreview(UpdateMediaSettingsRequest $request, string $mediaProject): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        $options = $request->validate(['action' => ['sometimes', Rule::in(['regenerate', 'hook', 'shorten', 'expand', 'names'])], 'kind' => ['required', Rule::in(['voice', 'video', 'timing', 'shorten', 'rewrite', 'fit'])], 'line' => ['sometimes', 'integer', 'min:0', 'max:5999'], 'start' => ['sometimes', 'numeric', 'min:0'], 'duration' => ['sometimes', 'numeric', 'between:1,15'], 'lines' => ['sometimes', 'array', 'min:1', 'max:6000'], 'lines.*.index' => ['required', 'integer', 'distinct'], 'lines.*.text' => ['required', 'string', 'max:1500']]);
        $assignment = $project->user->currentPackageAssignment;
        $feature = match ($options['kind']) {
            'video' => 'video_preview', 'voice', 'timing' => 'voice_preview', default => 'script_edit'
        };
        if (! StudioTesting::enabled() && $assignment && ! ($assignment->effectiveSnapshot()['definition']['features'][$feature] ?? false)) {
            throw ValidationException::withMessages(['kind' => 'This preview or editing tool is not included in your package.']);
        }
        if ($options['kind'] === 'fit' && ! StudioTesting::enabled() && $assignment && ! ($assignment->effectiveSnapshot()['definition']['features']['voice_preview'] ?? false)) {
            throw ValidationException::withMessages(['kind' => 'Voice preview is required to measure narration timing.']);
        }
        abort_unless(($project->metadata['audio'] ?? false) && app(MediaRuntime::class)->available(), 422);
        abort_unless(app(ProviderSettings::class)->ready(), 422);
        $settings = MediaSettings::normalize($request->validated('settings') ?? $project->settings);
        if (in_array($options['kind'], ['voice', 'timing', 'fit'], true) && $settings['mode'] !== 'dub') {
            abort(422);
        }
        abort_if($options['kind'] === 'fit' && ! MediaNarrationFit::enabled($settings), 422);
        $lines = $project->lines ?? [];
        if (isset($options['lines'])) {
            $updates = collect($options['lines'])->keyBy('index');
            abort_unless(count($lines) === $updates->count() && array_keys($lines) === $updates->keys()->sort()->values()->all(), 422);
            foreach ($lines as &$line) {
                $line['text'] = trim($updates[$line['index']]['text']);
                abort_if($line['text'] === '', 422);
            }
            unset($line);
        }
        if ($options['kind'] !== 'voice') {
            abort_if($lines === [], 422);
        }
        if (isset($options['line'])) {
            abort_unless(isset($lines[$options['line']]), 422);
        }
        if (in_array($options['kind'], ['shorten', 'rewrite'], true)) {
            abort_unless(isset($options['line']), 422);
        }
        unset($options['lines']);
        if ($options['kind'] === 'fit') {
            $options = ['kind' => 'fit'];
        }
        if ($options['kind'] === 'video') {
            $options['start'] = $options['start'] ?? ($lines[0]['start'] ?? 0);
            $options['duration'] = min($options['duration'] ?? 15, $project->metadata['duration'] - $options['start']);
            abort_if($options['duration'] <= 0, 422);
        }
        $providerRevision = app(ProviderSettings::class)->snapshot();
        $key = hash('sha256', json_encode([MediaRenderer::AUDIO_REVISION, $project->source_path, $settings, $lines, $options, $providerRevision, ...($options['kind'] === 'fit' ? ['fixed-target-v2'] : [])], JSON_THROW_ON_ERROR));
        $preview = DB::transaction(function () use ($project, $settings, $lines, $options, $key, $providerRevision): MediaPreview {
            $locked = MediaProject::lockForUpdate()->findOrFail($project->id);
            $cached = $locked->previews()->where('cache_key', $key)->where('status', 'completed')->latest()->first();
            if ($cached) {
                return $cached;
            }
            abort_if($locked->busy(), 409);
            $preview = $locked->previews()->create(['provider_revision_id' => $providerRevision, 'kind' => $options['kind'], 'settings' => $settings, 'lines' => $lines, 'options' => $options, 'cache_key' => $key, 'status' => 'queued']);
            PrepareMediaPreview::dispatch($preview->id)->afterCommit();

            return $preview;
        });

        return response()->json(['preview' => $this->data($preview)], $preview->status === 'completed' ? 200 : 202);
    }

    public function preview(Request $request, string $mediaProject, string $preview): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);

        return response()->json(['preview' => $this->data($project->previews()->findOrFail($preview))]);
    }

    public function cancelPreview(Request $request, string $mediaProject, string $preview): JsonResponse
    {
        $project = $this->owned($request, $mediaProject);
        $record = DB::transaction(function () use ($project, $preview): MediaPreview {
            $record = $project->previews()->lockForUpdate()->findOrFail($preview);
            $record->update(['cancel_requested' => true, ...($record->status === 'queued' ? ['status' => 'cancelled'] : [])]);

            return $record;
        });

        return response()->json(['preview' => $this->data($record->fresh())]);
    }

    public function previewFile(Request $request, string $mediaProject, string $preview): BinaryFileResponse
    {
        $project = $this->owned($request, $mediaProject);
        $record = $project->previews()->findOrFail($preview);
        abort_unless($record->status === 'completed' && $record->path, 404);
        try {
            $path = app(MediaStorage::class)->materialize($project, $record->path);
        } catch (MediaFailure) {
            abort(404);
        }

        return response()->file($path, ['Content-Type' => $record->kind === 'video' ? 'video/mp4' : 'audio/wav', 'Cache-Control' => 'private, no-store']);
    }

    private function owned(Request $request, string $id): MediaProject
    {
        return MediaProject::where('user_id', $request->user()->id)->findOrFail($id);
    }

    public function data(MediaPreview $preview): array
    {
        $parameters = ['mediaProject' => $preview->media_project_id, 'preview' => $preview->id];

        $result = $preview->result;
        if ($preview->status === 'queued' && $preview->error_code === 'provider_rate_limit' && ! isset($result['retryAt'])) {
            $available = DB::table('jobs')->where('queue', 'media')->where('payload', 'like', '%'.$preview->id.'%')->value('available_at');
            if ($available) {
                $result = [...($result ?? []), 'retryAt' => CarbonImmutable::createFromTimestamp((int) $available)->toIso8601String()];
            }
        }

        return ['id' => $preview->id, 'kind' => $preview->kind, 'status' => $preview->status, 'progress' => $preview->progress, 'errorCode' => $preview->error_code, 'cancelRequested' => $preview->cancel_requested, 'matchesCurrent' => MediaSettings::normalize($preview->settings) == MediaSettings::normalize($preview->project->settings) && $preview->lines == ($preview->project->lines ?? []), 'result' => $result, 'links' => ['status' => route('media.preview.status', $parameters, false), 'cancel' => route('media.preview.cancel', $parameters, false), 'file' => $preview->status === 'completed' && $preview->path ? route('media.preview.file', $parameters, false) : null]];
    }
}
