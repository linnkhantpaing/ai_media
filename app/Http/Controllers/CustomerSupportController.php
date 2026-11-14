<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\SupportController;
use App\Services\SupportInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CustomerSupportController extends Controller
{
    public function index(Request $request, SupportInbox $inbox): JsonResponse
    {
        $filters = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'tab' => ['nullable', Rule::in(['all', 'unread', 'resolved'])]]);

        return response()->json($inbox->listing($request->user(), array_filter($filters, fn ($v) => $v !== null), false))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, SupportInbox $inbox): JsonResponse
    {
        $v = $request->validate(SupportController::messageRules() + ['subject' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(SupportInbox::CATEGORIES)], 'kind' => ['prohibited'], 'customer_id' => ['prohibited'], 'priority' => ['prohibited'],
            'related_type' => ['nullable', 'required_with:related_id', Rule::in(['payment', 'job', 'subscription'])],
            'related_id' => ['nullable', 'required_with:related_type', 'integer', 'min:1']]);
        unset($v['reply_to']);

        return response()->json(['id' => $inbox->create($request->user(), $v, $request->file('files', []), false)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, SupportInbox $inbox, int $conversation): JsonResponse
    {
        $v = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);

        return response()->json($inbox->detail($request->user(), $conversation, false, isset($v['before']) ? (int) $v['before'] : null))->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, SupportInbox $inbox, int $conversation): JsonResponse
    {
        $v = $request->validate(SupportController::messageRules() + ['kind' => ['prohibited']]);

        return response()->json(['id' => $inbox->send($request->user(), $conversation, $v, $request->file('files', []), false)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, SupportInbox $inbox, int $conversation): JsonResponse
    {
        $v = $request->validate(['through' => ['required', 'integer', 'min:0']]);
        $inbox->markRead($request->user(), $conversation, (int) $v['through'], false);

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    public function attachment(Request $request, SupportInbox $inbox, int $conversation, string $attachment): Response
    {
        $file = $inbox->attachment($request->user(), $conversation, $attachment, false);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        $headers = ['Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox"];

        return $request->boolean('download') || $file->mime === 'application/pdf'
            ? Storage::disk('local')->download($file->path, $file->name, $headers)
            : Storage::disk('local')->response($file->path, $file->name, $headers);
    }
}
