<?php

namespace App\Http\Controllers;

use App\Services\CustomerNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerNotificationController extends Controller
{
    public function __construct(private CustomerNotifications $notifications) {}

    public function index(Request $request): Response|JsonResponse
    {
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'unread', ...CustomerNotifications::CATEGORIES])],
            'status' => ['nullable', Rule::in(['', 'unread', 'read'])],
            'period' => ['nullable', Rule::in(['7', '30', 'all'])],
            'archived' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $data = $this->notifications->listing($request->user(), [...$filters, 'archived' => $request->boolean('archived')]);
        $data['endpoints'] = ['index' => route('notifications.index', absolute: false), 'action' => route('notifications.action', absolute: false)];
        if ($request->expectsJson()) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('account/notifications', ['frame' => 'notifications', 'notifications' => $data]);
    }

    public function feed(Request $request): JsonResponse
    {
        return response()->json($this->notifications->feed($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function action(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['read', 'unread', 'archive', 'unarchive', 'read_all'])],
            'ids' => ['required_unless:action,read_all', 'array', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
        ]);
        $changed = $this->notifications->act($request->user(), $data['action'], array_map('intval', $data['ids'] ?? []));

        return response()->json(['changed' => $changed, ...$this->notifications->feed($request->user())])->header('Cache-Control', 'private, no-store');
    }
}
