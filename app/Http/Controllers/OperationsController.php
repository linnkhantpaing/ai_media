<?php

namespace App\Http\Controllers;

use App\Exceptions\MediaFailure;
use App\Models\MediaTask;
use App\Services\Media\MediaRunActions;
use App\Services\Media\MediaStorage;
use App\Services\Media\OperationsMonitor;
use App\Services\Media\YouTubeImports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OperationsController extends Controller
{
    public function index(Request $request, OperationsMonitor $monitor): Response
    {
        return Inertia::render('operations/jobs', ['frame' => 'jobMonitor', ...$monitor->browse($request)]);
    }

    public function workers(OperationsMonitor $monitor): Response
    {
        return Inertia::render('operations/workers', ['frame' => 'workerHealth', ...$monitor->health()]);
    }

    public function show(Request $request, MediaTask $mediaTask, OperationsMonitor $monitor): JsonResponse
    {
        return response()->json(['job' => $monitor->detail($mediaTask, $request->user()->id), 'refreshedAt' => now()->toIso8601String()]);
    }

    public function action(Request $request, MediaTask $mediaTask, OperationsMonitor $monitor, MediaRunActions $runs, YouTubeImports $imports): JsonResponse
    {
        $input = $request->validate(['action' => ['required', Rule::in(['retry', 'cancel'])], 'confirm' => ['required', 'accepted']]);
        $mediaTask->load(['project', 'run']);
        $retry = $input['action'] === 'retry';
        if (! ($retry ? $monitor->canRetry($mediaTask) : $monitor->canCancel($mediaTask))) {
            return response()->json(['code' => 'project_busy'], 409);
        }
        try {
            if ($mediaTask->type === 'import') {
                $retry ? $imports->retry($mediaTask->project, $mediaTask->id) : $imports->cancel($mediaTask->project, $mediaTask->id);
            } else {
                $retry ? $runs->retry($mediaTask->project, $mediaTask->media_run_id, $mediaTask->type) : $runs->cancel($mediaTask->project, $mediaTask->media_run_id, $mediaTask->type);
            }
        } catch (MediaFailure $failure) {
            return response()->json(['code' => $failure->reason], $failure->reason === 'project_busy' ? 409 : 422);
        }

        return response()->json(['job' => $monitor->detail($mediaTask->fresh(), $request->user()->id)], 202);
    }

    public function file(Request $request, MediaTask $mediaTask, string $kind, OperationsMonitor $monitor): BinaryFileResponse
    {
        $relative = $monitor->filePath($mediaTask, $kind);
        abort_unless($relative, 404);
        try {
            $file = app(MediaStorage::class)->materialize($mediaTask->project, $relative);
        } catch (MediaFailure) {
            abort(404);
        }
        $extension = $kind === 'subtitles' ? '.srt' : ($kind === 'audio' ? '.'.pathinfo($relative, PATHINFO_EXTENSION) : '.mp4');
        if ($kind === 'subtitles' || $request->boolean('download')) {
            $response = response()->download($file, (Str::slug($mediaTask->project->title) ?: 'ai-media').$extension);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        return response()->file($file, ['Content-Type' => $kind === 'poster' ? 'image/jpeg' : ($kind === 'audio' ? (str_ends_with($relative, '.mp3') ? 'audio/mpeg' : 'audio/wav') : 'video/mp4'), 'Cache-Control' => 'private, no-store']);
    }
}
