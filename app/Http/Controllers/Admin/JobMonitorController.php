<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\MediaFailure;
use App\Http\Controllers\Controller;
use App\Models\MediaTask;
use App\Models\MediaWorker;
use App\Services\AdminJobMonitor;
use App\Services\AdminWorkerHealth;
use App\Services\Media\MediaStorage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class JobMonitorController extends Controller
{
    public function index(Request $request, AdminJobMonitor $monitor): Response|JsonResponse
    {
        $data = $monitor->browse($request);

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'private, no-store')
            : Inertia::render('admin/job-monitor', ['monitor' => $data]);
    }

    public function show(MediaTask $mediaTask, AdminJobMonitor $monitor): JsonResponse
    {
        return response()->json($monitor->detail($mediaTask))->header('Cache-Control', 'private, no-store');
    }

    public function action(Request $request, MediaTask $mediaTask, AdminJobMonitor $monitor): JsonResponse
    {
        $input = $request->validate(['action' => ['required', Rule::in(['retry', 'cancel'])],
            'confirm' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:3', 'max:500'],
            'request_id' => ['required', 'uuid']]);
        try {
            $result = $monitor->act($request->user('admin'), $mediaTask, $input);
        } catch (MediaFailure $failure) {
            return response()->json(['code' => $failure->reason], $failure->reason === 'project_busy' ? 409 : 422);
        } catch (LockTimeoutException) {
            return response()->json(['code' => 'project_busy'], 409);
        }

        return response()->json($result, 202)->header('Cache-Control', 'private, no-store');
    }

    public function file(Request $request, MediaTask $mediaTask, string $kind, AdminJobMonitor $monitor): BinaryFileResponse
    {
        $mediaTask->load(['project', 'run']);
        $path = $monitor->filePath($mediaTask, $kind);
        abort_unless($path, 404);
        try {
            $file = app(MediaStorage::class)->materialize($mediaTask->project, $path);
        } catch (\Throwable) {
            abort(404);
        }
        if ($kind === 'subtitles' || $request->boolean('download')) {
            return response()->download($file, (Str::slug($mediaTask->project->title) ?: 'zatlan').'.'.pathinfo($path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store']);
        }

        return response()->file($file, ['Content-Type' => $kind === 'poster' ? 'image/jpeg' : ($kind === 'audio' ? (str_ends_with($path, '.mp3') ? 'audio/mpeg' : 'audio/wav') : 'video/mp4'),
            'Cache-Control' => 'private, no-store']);
    }

    public function workers(Request $request, AdminWorkerHealth $monitor): Response|JsonResponse
    {
        $health = $monitor->browse($request);

        return $request->expectsJson() ? response()->json($health)->header('Cache-Control', 'private, no-store')
            : Inertia::render('admin/worker-health', ['health' => $health]);
    }

    public function worker(MediaWorker $mediaWorker, AdminWorkerHealth $monitor): JsonResponse
    {
        return response()->json($monitor->detail($mediaWorker))->header('Cache-Control', 'private, no-store');
    }
}
