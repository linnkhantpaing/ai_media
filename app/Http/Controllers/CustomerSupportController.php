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
    public function chat(Request $request, SupportInbox $inbox): JsonResponse
    {
        $input = $request->validate([
            'badge' => ['sometimes', 'boolean'],
            'before' => ['nullable', 'integer', 'min:1', 'prohibits:after'],
            'after' => ['nullable', 'integer', 'min:0'],
            'loaded_from' => ['nullable', 'integer', 'min:1', 'required_with:since'],
            'since' => ['nullable', 'integer', 'min:0', 'required_with:loaded_from'],
        ]);

        $result = $request->boolean('badge')
            ? ['unread' => $inbox->customerChatUnread($request->user())]
            : $inbox->customerChat($request->user(), $input['before'] ?? null, $input['after'] ?? null, $input['loaded_from'] ?? null, $input['since'] ?? null);

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function sendChat(Request $request, SupportInbox $inbox): JsonResponse
    {
        $input = $request->validate(SupportController::messageRules() + ['kind' => ['prohibited']]);
        $id = $inbox->sendCustomerChat($request->user(), $input, $request->file('files', []));

        return response()->json(['id' => $id], 201)->header('Cache-Control', 'private, no-store');
    }

    public function readChat(Request $request, SupportInbox $inbox): JsonResponse
    {
        $request->validate(['through' => ['required', 'integer', 'min:1']]);
        $unread = $inbox->readCustomerChat($request->user(), $request->integer('through'));

        return response()->json(['unread' => $unread])->header('Cache-Control', 'private, no-store');
    }

    public function editChatMessage(Request $request, SupportInbox $inbox, int $message): JsonResponse
    {
        $input = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $inbox->changeCustomerMessage($request->user(), $message, trim($input['body']));

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    public function deleteChatMessage(Request $request, SupportInbox $inbox, int $message): JsonResponse
    {
        $inbox->changeCustomerMessage($request->user(), $message, null);

        return response()->json(['deleted' => true])->header('Cache-Control', 'private, no-store');
    }

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
        $v = $request->validate(['before' => ['nullable', 'integer', 'min:1'], 'loaded_from' => ['nullable', 'integer', 'min:1']]);

        return response()->json($inbox->detail($request->user(), $conversation, false, isset($v['before']) ? (int) $v['before'] : null, isset($v['loaded_from']) ? (int) $v['loaded_from'] : null))->header('Cache-Control', 'private, no-store');
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
