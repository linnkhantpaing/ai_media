<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ScheduledTasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ScheduledTaskController extends Controller
{
    public function index(Request $request, ScheduledTasks $tasks): Response|JsonResponse
    {
        $data = $tasks->overview();
        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return Inertia::render('admin/scheduled-tasks', ['overview' => $data, 'endpoints' => [
            'index' => route('admin.schedules.index', absolute: false),
            'history' => route('admin.schedules.history', ['task' => 'TASK'], false),
            'run' => route('admin.schedules.run', ['task' => 'TASK'], false),
        ]]);
    }

    public function history(Request $request, string $task, ScheduledTasks $tasks): JsonResponse
    {
        $input = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', Rule::in(['success', 'failed'])]]);

        return response()->json($tasks->history($task, (int) ($input['page'] ?? 1), (string) ($input['status'] ?? '')));
    }

    public function run(Request $request, string $task, ScheduledTasks $tasks): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        $tasks->request($task, $request->user('admin'));

        return response()->json($tasks->overview(), 202);
    }
}
