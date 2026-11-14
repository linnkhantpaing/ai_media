<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SupportInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class SupportController extends Controller
{
    public static function messageRules(): array
    {
        return ['body' => ['nullable', 'required_without:files', 'string', 'max:4000'],
            'request_id' => ['required', 'uuid'], 'reply_to' => ['nullable', 'integer', 'min:1'],
            'files' => ['nullable', 'array', 'max:5'], 'files.*' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf', 'extensions:jpg,jpeg,png,webp,pdf']];
    }

    public function index(Request $request, SupportInbox $inbox): Response|JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', Rule::in(SupportInbox::CATEGORIES)],
            'tab' => ['nullable', Rule::in(['all', 'unread', 'needs_reply', 'in_progress', 'resolved'])],
            'sort' => ['nullable', Rule::in(['latest', 'waiting'])], 'after' => ['nullable', 'string', 'max:2048'],
            'conversation' => ['nullable', 'integer', 'min:1']]);
        unset($filters['conversation']);
        $data = $inbox->listing($request->user('admin'), array_filter($filters, fn ($v) => $v !== null));
        $data['endpoints'] = ['index' => route('admin.support.index', absolute: false), 'options' => route('admin.support.options', absolute: false)];
        $data['selected'] = $request->integer('conversation') ?: null;
        if ($request->expectsJson()) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('admin/support', ['inbox' => $data]);
    }

    public function options(Request $request, SupportInbox $inbox): JsonResponse
    {
        $v = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'customer' => ['nullable', 'integer', 'min:1']]);

        return response()->json($inbox->options($v['q'] ?? '', isset($v['customer']) ? (int) $v['customer'] : null))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, SupportInbox $inbox): JsonResponse
    {
        $data = $request->validate(self::messageRules() + [
            'customer_id' => ['required', 'integer', 'min:1'], 'subject' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(SupportInbox::CATEGORIES)], 'priority' => ['nullable', Rule::in(SupportInbox::PRIORITIES)],
            'related_type' => ['nullable', 'required_with:related_id', Rule::in(['job', 'payment', 'subscription'])],
            'related_id' => ['nullable', 'required_with:related_type', 'integer', 'min:1'],
        ]);
        unset($data['reply_to']);
        $id = $inbox->create($request->user('admin'), $data, $request->file('files', []));

        return response()->json(['id' => $id], 201)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, SupportInbox $inbox, int $conversation): JsonResponse
    {
        $v = $request->validate(['before' => ['nullable', 'integer', 'min:1'], 'loaded_from' => ['nullable', 'integer', 'min:1']]);

        return response()->json($inbox->detail($request->user('admin'), $conversation, true, isset($v['before']) ? (int) $v['before'] : null, isset($v['loaded_from']) ? (int) $v['loaded_from'] : null))->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, SupportInbox $inbox, int $conversation): JsonResponse
    {
        $data = $request->validate(self::messageRules() + ['kind' => ['required', Rule::in(['reply', 'note'])]]);
        $id = $inbox->send($request->user('admin'), $conversation, $data, $request->file('files', []));

        return response()->json(['id' => $id], 201)->header('Cache-Control', 'private, no-store');
    }

    public function destroyMessage(Request $request, SupportInbox $inbox, int $conversation, int $message): JsonResponse
    {
        $inbox->deleteMessage($request->user('admin'), $conversation, $message);

        return response()->json(['deleted' => true])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, SupportInbox $inbox, int $conversation): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'status' => ['sometimes', Rule::in(SupportInbox::STATUSES)],
            'category' => ['sometimes', Rule::in(SupportInbox::CATEGORIES)], 'priority' => ['sometimes', Rule::in(SupportInbox::PRIORITIES)]]);
        $inbox->update($request->user('admin'), $conversation, $data);

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, SupportInbox $inbox, int $conversation): JsonResponse
    {
        $v = $request->validate(['through' => ['required', 'integer', 'min:0']]);
        $inbox->markRead($request->user('admin'), $conversation, (int) $v['through']);

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    public function attachment(Request $request, SupportInbox $inbox, int $conversation, string $attachment): HttpResponse
    {
        $file = $inbox->attachment($request->user('admin'), $conversation, $attachment);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        $headers = ['Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox"];

        return $request->boolean('download') || $file->mime === 'application/pdf'
            ? Storage::disk('local')->download($file->path, $file->name, $headers)
            : Storage::disk('local')->response($file->path, $file->name, $headers);
    }
}
