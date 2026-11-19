<?php

namespace App\Http\Controllers;

use App\Models\MediaPreview;
use App\Models\MediaTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerQueueController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = MediaTask::query()
            ->whereHas('project', fn ($project) => $project->where('user_id', $request->user()->id))
            ->whereIn('status', ['queued', 'running', 'retry_waiting', 'stopping']);
        $counts = (clone $query)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $tasks = $query->with('project:id,title')->orderByDesc('updated_at')->orderByDesc('id')->limit(8)->get();
        $previewIds = $tasks->where('type', 'preview')->pluck('source_key')->map(fn ($key) => str_starts_with((string) $key, 'preview:') ? substr($key, 8) : null)->filter();
        $previewKinds = MediaPreview::whereIn('id', $previewIds)->pluck('kind', 'id');
        $items = $tasks->map(function (MediaTask $task) use ($previewKinds): array {
            $source = in_array($task->type, ['import', 'validation'], true);
            $url = route($source ? 'media.upload.page' : 'media.analysis', ['mediaProject' => $task->media_project_id], absolute: false);
            if ($task->type === 'preview' && ! in_array($previewKinds->get(substr((string) $task->source_key, 8)), ['rewrite', 'shorten'], true)) {
                $url .= '?studio=render';
            }

            return [
                'id' => $task->id, 'title' => $task->project->title, 'type' => $task->type,
                'status' => $task->status, 'progress' => max(0, min(100, $task->progress)),
                'url' => $url,
            ];
        });

        return response()->json([
            'total' => (int) $counts->sum(),
            'running' => (int) ($counts['running'] ?? 0),
            'queued' => (int) ($counts['queued'] ?? 0),
            'retrying' => (int) ($counts['retry_waiting'] ?? 0),
            'stopping' => (int) ($counts['stopping'] ?? 0),
            'items' => $items,
        ])->header('Cache-Control', 'private, no-store');
    }
}
