<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveNameDictionaryRequest;
use App\Http\Requests\SaveRecapScenesRequest;
use App\Models\MediaProject;
use App\Models\User;
use App\Support\StudioTesting;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecapStudioController extends Controller
{
    public function dictionary(SaveNameDictionaryRequest $request): JsonResponse
    {
        $dictionary = array_map(fn (array $entry): array => [
            'original' => trim($entry['original']),
            'translated' => trim($entry['translated']),
            'pronunciation' => trim((string) ($entry['pronunciation'] ?? '')),
        ], array_values($request->validated('name_dictionary')));

        DB::transaction(function () use ($request, $dictionary): void {
            $projectId = $request->validated('project_id');
            if ($projectId) {
                $project = MediaProject::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($projectId);
                if ($project->busy()) {
                    throw new HttpResponseException(response()->json(['code' => 'project_busy'], 409));
                }
                $project->forceFill(['settings' => [...$project->settings, 'name_dictionary' => $dictionary]])->save();
            }
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail()
                ->forceFill(['recap_name_dictionary' => $dictionary])->save();
        });

        return response()->json(['name_dictionary' => $dictionary]);
    }

    public function scenes(SaveRecapScenesRequest $request, string $mediaProject): JsonResponse
    {
        DB::transaction(function () use ($request, $mediaProject): void {
            $project = MediaProject::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($mediaProject);
            abort_unless($project->status === 'awaiting_review' && ! $project->busy(), 409);
            if (! hash_equals(hash('sha256', json_encode($project->lines ?? [])), $request->validated('revision'))) {
                throw ValidationException::withMessages(['lines' => 'The script changed in another tab. Reload before saving.']);
            }
            $lines = array_values($request->validated('lines'));
            $assignment = $project->user->currentPackageAssignment;
            if (! StudioTesting::enabled() && $assignment && ! ($assignment->effectiveSnapshot()['definition']['features']['script_edit'] ?? false)) {
                $canonical = fn (array $items): array => array_map(fn (array $line): array => [
                    'start' => (float) $line['start'], 'end' => (float) $line['end'], 'text' => $line['text'],
                    'subtitle_text' => $line['subtitle_text'] ?? $line['text'], 'source_text' => $line['source_text'],
                    'included' => (bool) ($line['included'] ?? true), 'locked' => (bool) ($line['locked'] ?? false),
                    'audio_muted' => (bool) ($line['audio_muted'] ?? false), 'subtitle_hidden' => (bool) ($line['subtitle_hidden'] ?? false),
                    'note' => (string) ($line['note'] ?? ''), 'planned_duration' => $line['planned_duration'] ?? null,
                ], $items);
                if ($canonical($lines) != $canonical($project->lines ?? [])) {
                    throw ValidationException::withMessages(['lines' => 'Script editing is not included in this package.']);
                }
            }
            $movie = ($project->settings['workflow'] ?? '') === 'movie';
            if (! collect($lines)->contains(fn (array $line): bool => $line['included'])) {
                throw ValidationException::withMessages(['lines' => 'Include at least one scene.']);
            }
            foreach ($lines as $i => &$line) {
                if ($line['end'] <= $line['start'] || $line['end'] > (float) $project->metadata['duration'] || ! is_finite((float) $line['end'])) {
                    throw ValidationException::withMessages(['lines.'.$i.'.end' => 'Choose a valid source time range.']);
                }
                if (! $movie && ($line['start'] < ($lines[$i - 1]['end'] ?? 0))) {
                    throw ValidationException::withMessages(['lines' => 'Translated dialogue must stay in chronological order without overlaps.']);
                }
                $line['scene_id'] = $line['scene_id'] ?? (string) Str::uuid();
                $line['index'] = $i;
                $line['text'] = trim($line['text']);
                $line['note'] = (string) ($line['note'] ?? '');
                if ($line['text'] === '') {
                    throw ValidationException::withMessages(['lines.'.$i.'.text' => 'Enter narration text.']);
                }
            }
            unset($line);
            $project->forceFill(['lines' => $lines])->save();
            $project->activeRun()->update(['lines' => $lines]);
        });

        return app(MediaProjectController::class)->status($request, $mediaProject);
    }
}
