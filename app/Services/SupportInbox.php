<?php

namespace App\Services;

use App\Models\CustomerPackageAssignment;
use App\Models\MediaTask;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupportInbox
{
    public const CATEGORIES = ['video', 'payment', 'subscription', 'account', 'general'];

    public const STATUSES = ['open', 'in_progress', 'waiting_customer', 'resolved'];

    public const PRIORITIES = ['normal', 'high', 'urgent'];

    /** Messages returned per chat page, newest first, on both Admin and customer sides. */
    public const PAGE_SIZE = 20;

    /** Maximum messages returned when loading history down to a quoted message. */
    private const HISTORY_LIMIT = 200;

    public function authorize(User $actor, int $id, bool $admin): object
    {
        abort_unless($admin ? $actor->is_admin : ! $actor->is_admin, 403);
        $conversation = DB::table('support_conversations')->find($id);
        abort_unless($conversation && ($admin || $conversation->customer_id === $actor->id), 404);

        return $conversation;
    }

    private function iso(mixed $value): ?string
    {
        return $value ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;
    }

    private function reference(int $id): string
    {
        return 'SUP-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    private function base(User $actor, bool $admin): Builder
    {
        $query = DB::table('support_conversations as c')->join('users as u', 'u.id', '=', 'c.customer_id')
            ->leftJoin('support_reads as r', fn ($q) => $q->on('r.conversation_id', '=', 'c.id')->where('r.user_id', $actor->id));
        if (! $admin) {
            $query->where('c.customer_id', $actor->id);
        }

        return $query;
    }

    private function unread(Builder $query, bool $admin): Builder
    {
        return $query->whereExists(function (Builder $q) use ($admin): void {
            $q->selectRaw('1')->from('support_messages as m')->whereColumn('m.conversation_id', 'c.id')
                ->where('m.kind', 'reply')->whereNull('m.deleted_at')->where('m.sender_kind', $admin ? 'customer' : 'admin')
                ->whereRaw('m.id > COALESCE(r.last_message_id, 0)');
        });
    }

    public function listing(User $actor, array $filters = [], bool $admin = true): array
    {
        abort_unless($admin ? $actor->is_admin : ! $actor->is_admin, 403);
        $base = $this->base($actor, $admin);
        $needs = fn (Builder $q) => $q->where('c.status', '!=', 'resolved')->whereNotNull('c.last_customer_message_id')
            ->whereRaw('c.last_customer_message_id > COALESCE(c.last_admin_reply_id, 0)');
        $counts = ['all' => (clone $base)->count(), 'unread' => $this->unread(clone $base, $admin)->count(),
            'needs_reply' => $needs(clone $base)->count(), 'in_progress' => (clone $base)->where('c.status', 'in_progress')->count(),
            'resolved' => (clone $base)->where('c.status', 'resolved')->count()];
        $query = clone $base;
        $tab = $filters['tab'] ?? 'all';
        if ($tab === 'unread') {
            $this->unread($query, $admin);
        } elseif ($tab === 'needs_reply') {
            $needs($query);
        } elseif (in_array($tab, ['in_progress', 'resolved'], true)) {
            $query->where('c.status', $tab);
        }
        if (filled($filters['category'] ?? null)) {
            $query->where('c.category', $filters['category']);
        }
        if (filled($filters['q'] ?? null)) {
            $text = trim($filters['q']);
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
            $query->where(function (Builder $q) use ($term, $text): void {
                foreach (['c.subject', 'u.name', 'u.email'] as $field) {
                    $q->orWhereRaw($field." LIKE ? ESCAPE '!'", [$term]);
                }
                if (preg_match('/^(?:SUP-)?0*(\d+)$/i', $text, $match)) {
                    $q->orWhere('c.id', (int) $match[1]);
                }
            });
        }
        $total = (clone $query)->count();
        $waiting = ($filters['sort'] ?? 'latest') === 'waiting';
        $waitingGroup = "CASE WHEN c.last_customer_message_id > COALESCE(c.last_admin_reply_id, 0) AND c.status != 'resolved' THEN 0 ELSE 1 END";
        $waitingDate = "COALESCE(c.last_customer_at, '1970-01-01 00:00:00')";
        $scope = hash('sha256', json_encode([$actor->id, $admin, trim($filters['q'] ?? ''), $filters['category'] ?? '', $tab, $filters['sort'] ?? 'latest'], JSON_THROW_ON_ERROR));
        if ($admin && filled($filters['after'] ?? null)) {
            try {
                $cursor = json_decode(Crypt::decryptString($filters['after']), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($cursor) || ($cursor['scope'] ?? '') !== $scope || ! is_int($cursor['id'] ?? null)
                    || ! is_string($cursor['at'] ?? null) || ! is_string($cursor['waiting_at'] ?? null)
                    || ! in_array($cursor['group'] ?? null, [0, 1], true)) {
                    throw new \UnexpectedValueException;
                }
            } catch (\Throwable) {
                throw ValidationException::withMessages(['after' => 'The conversation list changed. Refresh the list and try again.']);
            }
            $query->where(function (Builder $q) use ($cursor, $waiting, $waitingGroup, $waitingDate): void {
                if ($waiting) {
                    $q->whereRaw($waitingGroup.' > ?', [$cursor['group']])
                        ->orWhere(fn (Builder $q) => $q->whereRaw($waitingGroup.' = ?', [$cursor['group']])->whereRaw($waitingDate.' > ?', [$cursor['waiting_at']]))
                        ->orWhere(fn (Builder $q) => $q->whereRaw($waitingGroup.' = ?', [$cursor['group']])->whereRaw($waitingDate.' = ?', [$cursor['waiting_at']])->where('c.id', '<', $cursor['id']));
                } else {
                    $q->where('c.last_activity_at', '<', $cursor['at'])
                        ->orWhere(fn (Builder $q) => $q->where('c.last_activity_at', $cursor['at'])->where('c.id', '<', $cursor['id']));
                }
            });
        }
        if ($waiting) {
            $query->orderByRaw($waitingGroup)->orderByRaw($waitingDate);
        } else {
            $query->orderByDesc('c.last_activity_at');
        }
        $unread = DB::table('support_messages as m')->selectRaw('COUNT(*)')->whereColumn('m.conversation_id', 'c.id')
            ->where('m.kind', 'reply')->whereNull('m.deleted_at')->where('m.sender_kind', $admin ? 'customer' : 'admin')->whereRaw('m.id > COALESCE(r.last_message_id,0)');
        $last = DB::table('support_messages as lm')->select('body')->whereColumn('lm.conversation_id', 'c.id')
            ->where('lm.kind', 'reply')->orderByDesc('lm.id')->limit(1);
        $query->select('c.*', 'u.name', 'u.email')->selectSub($unread, 'unread')->selectSub($last, 'preview')->selectSub((clone $last)->select('sender_kind'), 'preview_sender')->selectSub((clone $last)->select('deleted_at'), 'preview_deleted_at')->orderByDesc('c.id');
        $page = min(max(1, (int) ceil($total / 12)), (int) ($filters['page'] ?? 1));
        $records = $admin ? $query->limit(21)->get() : $query->offset(($page - 1) * 12)->limit(12)->get();
        $hasMore = $admin && $records->count() > 20;
        $records = $admin ? $records->take(20) : $records;
        $tail = $records->last();
        $next = $hasMore && $tail ? Crypt::encryptString(json_encode(['scope' => $scope, 'id' => $tail->id,
            'at' => $tail->last_activity_at, 'waiting_at' => $tail->last_customer_at ?? '1970-01-01 00:00:00',
            'group' => $tail->status !== 'resolved' && $tail->last_customer_message_id > ($tail->last_admin_reply_id ?? 0) ? 0 : 1], JSON_THROW_ON_ERROR)) : null;
        $rows = $records->map(fn ($row) => [
            'id' => $row->id, 'code' => $this->reference($row->id), 'subject' => $row->subject,
            'category' => $row->category, 'priority' => $row->priority, 'status' => $row->status,
            'customer' => ['id' => $row->customer_id, 'name' => $row->name, 'email' => $row->email],
            'unread' => (int) $row->unread, 'preview' => mb_substr($row->preview ?? '', 0, 160), 'preview_sender' => $row->preview_sender, 'preview_deleted' => $row->preview_deleted_at !== null,
            'at' => $this->iso($row->last_activity_at), 'waiting_at' => $this->iso($row->last_customer_at),
            'needs_reply' => $row->status !== 'resolved' && $row->last_customer_message_id > ($row->last_admin_reply_id ?? 0),
        ])->values()->all();
        unset($filters['after']);
        if ($admin) {
            unset($filters['page']);

            return ['items' => $rows, 'counts' => $counts, 'batch' => ['size' => 20, 'total' => $total, 'has_more' => $hasMore, 'next_cursor' => $next],
                'filters' => array_replace(['q' => '', 'category' => '', 'tab' => 'all', 'sort' => 'latest'], $filters)];
        }

        return ['items' => $rows, 'counts' => $counts, 'pagination' => ['page' => $page, 'pages' => max(1, (int) ceil($total / 12)), 'total' => $total, 'per_page' => 12],
            'filters' => array_replace(['q' => '', 'category' => '', 'tab' => 'all', 'sort' => 'latest', 'page' => $page], $filters, ['page' => $page])];
    }

    /**
     * One page of a conversation. "before" pages back through history; with
     * "until" it returns everything down to that message (for reply jumps).
     * "loaded_from" + "since" report deletions and edits in the loaded window.
     */
    public function detail(User $actor, int $id, bool $admin = true, ?int $before = null, ?int $loadedFrom = null, ?int $until = null, ?int $since = null): array
    {
        $c = $this->authorize($actor, $id, $admin);
        $snapshot = now();
        $query = DB::table('support_messages as m')->leftJoin('users as s', 's.id', '=', 'm.sender_id')->where('m.conversation_id', $id);
        if (! $admin) {
            $query->where('m.kind', '!=', 'note');
        }
        $query->select('m.*', 's.name as sender_name');
        [$rows, $hasOlder] = $this->page($query, $before, $until);
        $read = (int) DB::table('support_reads')->where('conversation_id', $id)->where('user_id', $actor->id)->value('last_message_id');
        $customerRead = (int) DB::table('support_reads')->where('conversation_id', $id)->where('user_id', $c->customer_id)->value('last_message_id');
        $messages = $this->messagePayloads($rows, $actor, $admin, $customerRead);
        $deletedQuery = DB::table('support_messages')->where('conversation_id', $id)->whereNotNull('deleted_at')
            ->whereBetween('id', [$loadedFrom ?? ($rows->first()?->id ?? 0), $rows->last()?->id ?? 0]);
        if (! $admin) {
            $deletedQuery->where('kind', '!=', 'note');
        }
        $deletedIds = $deletedQuery->pluck('id')->map(fn ($value) => (int) $value)->all();
        $changes = $loadedFrom && $since !== null && $before === null
            ? $this->changedMessages($query, $loadedFrom, (int) ($rows->last()?->id ?? $loadedFrom), $since, $snapshot)
            : collect();
        $user = User::findOrFail($c->customer_id);
        $context = $admin ? $this->customerContext($user) : null;
        $related = $this->related($c->customer_id, $c->related_type, $c->related_id, $admin);

        return ['id' => $id, 'code' => $this->reference($id), 'subject' => $c->subject, 'category' => $c->category,
            'status' => $c->status, 'priority' => $c->priority, 'revision' => $c->revision,
            'created_at' => $this->iso($c->created_at), 'last_customer_at' => $this->iso($c->last_customer_at),
            'customer' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'messages' => $messages, 'deleted_ids' => $deletedIds, 'has_older' => $hasOlder, 'read_through' => $read,
            'changes' => $changes->count() > 120 ? [] : $this->messagePayloads($changes, $actor, $admin, $customerRead),
            'reset' => $changes->count() > 120, 'snapshot' => $snapshot->timestamp,
            'latest_id' => $rows->last()?->id ?? 0, 'context' => $context, 'related' => $related];
    }

    /**
     * Newest-first page of messages returned in chronological order.
     *
     * @return array{0: Collection<int, object>, 1: bool}
     */
    private function page(Builder $query, ?int $before, ?int $until): array
    {
        $page = clone $query;
        if ($before) {
            $page->where('m.id', '<', $before);
        }
        if ($until && $before) {
            $page->where('m.id', '>=', $until);
        }
        $rows = $page->orderByDesc('m.id')->limit($until && $before ? self::HISTORY_LIMIT : self::PAGE_SIZE)->get()->reverse()->values();
        $hasOlder = $rows->isNotEmpty() && (clone $query)->where('m.id', '<', $rows->first()->id)->exists();

        return [$rows, $hasOlder];
    }

    /**
     * Loaded messages edited (or whose quoted message changed) since the client's snapshot.
     *
     * @return Collection<int, object>
     */
    private function changedMessages(Builder $query, int $from, int $to, int $since, CarbonInterface $snapshot): Collection
    {
        $floor = CarbonImmutable::createFromTimestampUTC(min($since, $snapshot->timestamp))->subSeconds(15);

        return (clone $query)->leftJoin('support_messages as quoted', 'quoted.id', '=', 'm.reply_to')
            ->whereBetween('m.id', [$from, $to])
            ->where(fn (Builder $items) => $items->where('m.updated_at', '>=', $floor)->orWhere('quoted.updated_at', '>=', $floor))
            ->orderByDesc('m.id')->limit(121)->get();
    }

    private function messagePayloads(Collection $rows, User $actor, bool $admin, int $customerRead = 0): array
    {
        $attachments = DB::table('support_attachments')->whereIn('message_id', $rows->whereNull('deleted_at')->pluck('id'))->get()->groupBy('message_id');
        $replyQuery = DB::table('support_messages')->whereIn('id', $rows->pluck('reply_to')->filter())
            ->whereIn('conversation_id', $rows->pluck('conversation_id')->unique());
        if (! $admin) {
            $replyQuery->where('kind', 'reply');
        }
        $references = $replyQuery->get(['id', 'conversation_id', 'body', 'kind', 'deleted_at'])->keyBy('id');

        return $rows->map(function ($message) use ($actor, $attachments, $admin, $customerRead, $references): array {
            $reply = $references->get($message->reply_to);
            $deleted = $message->deleted_at !== null;
            $mine = $message->sender_id === $actor->id && $message->sender_kind === ($admin ? 'admin' : 'customer');
            $edited = ! $deleted && $message->kind !== 'event' && $message->updated_at > $message->created_at;

            return ['id' => $message->id, 'kind' => $message->kind, 'sender_kind' => $message->sender_kind, 'sender' => $message->sender_name,
                'body' => $deleted ? '' : $message->body, 'deleted' => $deleted, 'edited' => $edited,
                'edited_at' => $edited ? $this->iso($message->updated_at) : null,
                'can_delete' => $mine && ! $deleted && $message->kind !== 'event',
                'can_edit' => $mine && ! $deleted && in_array($message->kind, $admin ? ['reply', 'note'] : ['reply'], true) && filled($message->body),
                'event' => $message->event ? json_decode($message->event, true) : null, 'at' => $this->iso($message->created_at),
                'request_id' => $message->request_id,
                'read_by_customer' => $message->kind === 'reply' && $message->sender_kind === 'admin' && $customerRead >= $message->id,
                'reply' => ! $deleted && $reply && $reply->conversation_id === $message->conversation_id ? [
                    'id' => $reply->id, 'body' => $reply->deleted_at ? '' : mb_substr($reply->body, 0, 180),
                    'kind' => $reply->kind, 'deleted' => $reply->deleted_at !== null,
                ] : null,
                'attachments' => ($attachments->get($message->id) ?? collect())->map(fn ($file) => [
                    'id' => $file->id, 'name' => $file->name, 'mime' => $file->mime, 'bytes' => $file->bytes,
                    'url' => route($admin ? 'admin.support.attachment' : 'support.attachment', ['conversation' => $message->conversation_id, 'attachment' => $file->id], absolute: false),
                ])->all()];
        })->values()->all();
    }

    private function customerMessages(User $actor): Builder
    {
        abort_if($actor->is_admin, 403);

        return DB::table('support_messages as m')
            ->join('support_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.customer_id', $actor->id)->where('m.kind', 'reply');
    }

    public function customerChatUnread(User $actor): int
    {
        abort_if($actor->is_admin, 403);

        return $this->customerMessages($actor)
            ->leftJoin('support_reads as r', fn ($join) => $join->on('r.conversation_id', '=', 'c.id')->where('r.user_id', $actor->id))
            ->where('m.sender_kind', 'admin')->whereNull('m.deleted_at')
            ->whereRaw('m.id > COALESCE(r.last_message_id, 0)')->count();
    }

    public function customerChat(User $actor, ?int $before, ?int $after, ?int $loadedFrom, ?int $since, ?int $until = null): array
    {
        $snapshot = now();
        $query = $this->customerMessages($actor)->leftJoin('users as s', 's.id', '=', 'm.sender_id')
            ->select('m.*', 's.name as sender_name');
        $hasNewer = false;
        $hasOlder = false;
        if ($after !== null) {
            $raw = (clone $query)->where('m.id', '>', $after)->orderBy('m.id')->limit(self::PAGE_SIZE + 1)->get();
            $hasNewer = $raw->count() > self::PAGE_SIZE;
            $rows = $raw->take(self::PAGE_SIZE)->values();
        } else {
            [$rows, $hasOlder] = $this->page($query, $before, $until);
        }
        $changes = $after !== null && $loadedFrom && $since !== null
            ? $this->changedMessages($query, $loadedFrom, $after, $since, $snapshot)
            : collect();

        return ['messages' => $this->messagePayloads($rows, $actor, false),
            'changes' => $changes->count() > 120 ? [] : $this->messagePayloads($changes, $actor, false),
            'reset' => $changes->count() > 120,
            'has_older' => $hasOlder,
            'has_newer' => $hasNewer,
            'snapshot' => $snapshot->timestamp,
            'unread' => $this->customerChatUnread($actor)];
    }

    public function sendCustomerChat(User $actor, array $data, array $files): int
    {
        abort_if($actor->is_admin, 403);

        // The customer row serializes initial sends and keeps retries in their original case.
        return DB::transaction(function () use ($actor, $data, $files): int {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($prior = $this->customerMessages($actor)->where('m.request_id', $data['request_id'])->first(['m.conversation_id'])) {
                return $this->send($actor, $prior->conversation_id, $data, $files, false);
            }
            if (filled($data['reply_to'] ?? null)) {
                $target = $this->customerMessages($actor)->where('m.id', $data['reply_to'])->value('m.conversation_id');
                if (! $target) {
                    throw ValidationException::withMessages(['reply_to' => 'Choose an available message in this chat.']);
                }

                return $this->send($actor, $target, $data, $files, false);
            }

            $priorConversation = DB::table('support_conversations')->where('request_id', $data['request_id'])->where('customer_id', $actor->id)->exists();
            $general = DB::table('support_conversations')->where('customer_id', $actor->id)
                ->whereNull('related_type')->orderByDesc('id')->value('id');

            if ($general && ! $priorConversation) {
                return $this->send($actor, $general, $data, $files, false);
            }

            $id = $this->create($actor, $data + ['subject' => 'Customer support', 'category' => 'general'], $files, false);

            return (int) DB::table('support_messages')->where('conversation_id', $id)->orderBy('id')->value('id');
        });
    }

    public function readCustomerChat(User $actor, int $through): int
    {
        $visible = $this->customerMessages($actor);
        abort_unless((clone $visible)->where('m.id', $through)->exists(), 404);
        $read = $visible->leftJoin('support_reads as r', fn ($join) => $join->on('r.conversation_id', '=', 'c.id')->where('r.user_id', $actor->id))
            ->where('m.sender_kind', 'admin')->whereNull('m.deleted_at')->where('m.id', '<=', $through)
            ->whereRaw('m.id > COALESCE(r.last_message_id, 0)')->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id, MAX(m.id) as through')->get();
        foreach ($read as $row) {
            $this->markRead($actor, (int) $row->conversation_id, (int) $row->through, false);
        }

        return $this->customerChatUnread($actor);
    }

    public function changeCustomerMessage(User $actor, int $messageId, ?string $body): void
    {
        $message = $this->customerMessages($actor)->where('m.id', $messageId)->first(['m.conversation_id']);
        abort_unless($message, 404);
        if ($body === null) {
            $this->deleteMessage($actor, $message->conversation_id, $messageId, false);

            return;
        }
        $this->editMessage($actor, $message->conversation_id, $messageId, $body, false);
    }

    /**
     * Edit the sender's own text message. Customers edit their replies; Admins
     * edit their replies and internal notes. The other side sees it as edited.
     */
    public function editMessage(User $actor, int $id, int $messageId, string $body, bool $admin = true): void
    {
        $this->authorize($actor, $id, $admin);
        DB::transaction(function () use ($actor, $id, $messageId, $body, $admin): void {
            $conversation = DB::table('support_conversations')->where('id', $id)->lockForUpdate()->firstOrFail();
            $row = DB::table('support_messages')->where('conversation_id', $id)->where('id', $messageId)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->sender_kind === ($admin ? 'admin' : 'customer') && $row->sender_id === $actor->id
                && in_array($row->kind, $admin ? ['reply', 'note'] : ['reply'], true), 403);
            abort_if($row->deleted_at || blank($row->body), 409);
            if ($row->body === $body) {
                return;
            }
            $editedAt = max(now()->timestamp, CarbonImmutable::parse($row->created_at, 'UTC')->timestamp + 1);
            DB::table('support_messages')->where('id', $messageId)->update(['body' => $body, 'updated_at' => CarbonImmutable::createFromTimestampUTC($editedAt)]);
            DB::table('support_conversations')->where('id', $conversation->id)->update(['revision' => $conversation->revision + 1, 'updated_at' => now()]);
            app(AuditTrail::class)->record('support', $messageId.':edit:'.($conversation->revision + 1), 'accounts', 'support.message_edited', 'success',
                $actor->id, 'support', (string) $conversation->id, $this->reference($conversation->id), ['message_id' => $messageId]);
        });
    }

    public function customerContext(User $user): array
    {
        $assignment = CustomerPackageAssignment::current()->where('user_id', $user->id)->first();
        $snapshot = $assignment?->effectiveSnapshot();
        $payments = PaymentTransaction::where('user_id', $user->id)->latest('id')->limit(3)->get(['id', 'currency', 'received_minor', 'expected_minor', 'status', 'created_at']);
        $jobs = MediaTask::whereHas('project', fn ($q) => $q->where('user_id', $user->id))->with('project:id,title')->latest('id')->limit(3)->get();
        $storage = (int) DB::table('media_files')->join('media_projects', 'media_projects.id', '=', 'media_files.media_project_id')
            ->where('media_projects.user_id', $user->id)->whereNotNull('media_files.verified_at')->where('media_files.state', '!=', 'deleted')->sum('media_files.bytes');

        return ['status' => $user->account_status, 'created_at' => $this->iso($user->created_at), 'last_login' => $this->iso($user->last_login_at),
            'subscription' => $assignment ? ['name' => $snapshot['name'] ?? '', 'version' => $snapshot['version'] ?? null, 'status' => $assignment->status(),
                'from' => $this->iso($assignment->starts_at), 'to' => $this->iso($assignment->ends_at),
                'url' => route('admin.subscriptions.index', ['q' => 'SUB-'.str_pad((string) $assignment->id, 6, '0', STR_PAD_LEFT)], absolute: false)] : null,
            'payments' => $payments->map(fn ($p) => ['id' => 'PYT-'.str_pad((string) $p->id, 7, '0', STR_PAD_LEFT), 'currency' => $p->currency,
                'amount_minor' => $p->received_minor ?? $p->expected_minor, 'status' => $p->status, 'at' => $this->iso($p->created_at),
                'url' => route('admin.payments.index', ['q' => 'PYT-'.str_pad((string) $p->id, 7, '0', STR_PAD_LEFT)], absolute: false)])->all(),
            'jobs' => $jobs->map(fn ($j) => ['id' => 'J-'.str_pad((string) $j->id, 5, '0', STR_PAD_LEFT), 'title' => $j->project?->title,
                'status' => $j->status, 'url' => route('admin.jobs.index', ['q' => 'J-'.str_pad((string) $j->id, 5, '0', STR_PAD_LEFT), 'date' => 'all'], absolute: false)])->all(),
            'storage' => $storage, 'quota' => $snapshot['definition']['storage_bytes'] ?? null,
            'user_url' => route('admin.users.index', ['q' => $user->email], absolute: false),
            'usage_url' => route('admin.usage.index', ['q' => $user->email], absolute: false)];
    }

    public function related(int $customerId, ?string $type, ?int $id, bool $admin = true): ?array
    {
        if (! $type || ! $id) {
            return null;
        }
        $record = match ($type) {
            'payment' => PaymentTransaction::where('user_id', $customerId)->find($id),
            'subscription' => CustomerPackageAssignment::where('user_id', $customerId)->find($id),
            'job' => MediaTask::whereHas('project', fn ($q) => $q->where('user_id', $customerId))->with('project:id,title')->find($id),
            default => null,
        };
        if (! $record) {
            return null;
        }

        return $this->describeRelated($record, $type, $id, $admin);
    }

    private function describeRelated(object $record, string $type, int $id, bool $admin = true): array
    {
        $code = match ($type) {
            'payment' => 'PYT-'.str_pad((string) $id, 7, '0', STR_PAD_LEFT),
            'subscription' => 'SUB-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
            default => 'J-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT),
        };

        return ['type' => $type, 'id' => $id, 'code' => $code, 'title' => $type === 'job' ? $record->project?->title : $code,
            'status' => $type === 'subscription' ? $record->status() : $record->status,
            'url' => $admin ? route(match ($type) {
                'payment' => 'admin.payments.index', 'subscription' => 'admin.subscriptions.index', default => 'admin.jobs.index'
            }, ['q' => $code], absolute: false) : null];
    }

    public function options(string $search, ?int $customerId): array
    {
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        $users = User::where('is_admin', false)->where('account_status', '!=', 'archived')
            ->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("email LIKE ? ESCAPE '!'", [$term]))
            ->orderBy('name')->limit(30)->get(['id', 'name', 'email'])->toArray();
        $related = [];
        if ($customerId) {
            $user = User::where('is_admin', false)->findOrFail($customerId);
            foreach (PaymentTransaction::where('user_id', $user->id)->latest('id')->limit(20)->get(['id', 'status']) as $record) {
                $related[] = $this->describeRelated($record, 'payment', $record->id);
            }
            foreach (MediaTask::whereHas('project', fn ($q) => $q->where('user_id', $user->id))->with('project:id,title')->latest('id')->limit(20)->get(['id', 'media_project_id', 'status']) as $record) {
                $related[] = $this->describeRelated($record, 'job', $record->id);
            }
            foreach (CustomerPackageAssignment::where('user_id', $user->id)->latest('id')->limit(10)->get() as $record) {
                $related[] = $this->describeRelated($record, 'subscription', $record->id);
            }
        }

        return ['customers' => $users, 'related' => array_values(array_filter($related))];
    }

    private function validateUploads(array $files): void
    {
        if (count($files) > 5) {
            throw ValidationException::withMessages(['files' => 'Attach at most five files.']);
        }
        foreach ($files as $index => $file) {
            $mime = $file->getMimeType();
            $valid = in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'], true)
                && $file->getSize() > 0 && $file->getSize() <= 10 * 1024 * 1024;
            if ($valid && str_starts_with($mime, 'image/')) {
                $size = @getimagesize($file->getRealPath());
                $valid = $size && $size[0] <= 8000 && $size[1] <= 8000 && $size[0] * $size[1] <= 40000000;
            }
            if (! $valid) {
                throw ValidationException::withMessages(['files.'.$index => 'Use PNG, JPG, WEBP or PDF up to 10 MiB. Images must be within 8,000 pixels and 40 megapixels.']);
            }
        }
    }

    private function fingerprint(User $actor, array $data, array $files): string
    {
        $payload = $data;
        unset($payload['files']);
        $hashes = array_map(fn (UploadedFile $file) => [hash_file('sha256', $file->getRealPath()), $this->filename($file)], $files);

        return hash_hmac('sha256', json_encode([$actor->id, $payload, $hashes], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function filename(UploadedFile $file): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?: 'attachment', 0, 160);
    }

    public function create(User $actor, array $data, array $files = [], bool $admin = true): int
    {
        abort_unless($admin ? $actor->is_admin : ! $actor->is_admin, 403);
        $this->validateUploads($files);
        $customerId = $admin ? (int) $data['customer_id'] : $actor->id;
        $fingerprint = $this->fingerprint($actor, $data, $files);
        $paths = [];
        try {
            return DB::transaction(function () use ($actor, $data, $files, $admin, $customerId, $fingerprint, &$paths): int {
                $user = User::where('is_admin', false)->lockForUpdate()->findOrFail($customerId);
                abort_if($user->account_status === 'archived', 422, 'This account is archived.');
                if ($prior = DB::table('support_conversations')->where('request_id', $data['request_id'])->first()) {
                    abort_unless(hash_equals($prior->fingerprint, $fingerprint), 409);

                    return $prior->id;
                }
                $type = $data['related_type'] ?? null;
                $relatedId = isset($data['related_id']) ? (int) $data['related_id'] : null;
                if ($type && ! $this->related($customerId, $type, $relatedId)) {
                    throw ValidationException::withMessages(['related_id' => 'Choose a record belonging to this customer.']);
                }
                $id = DB::table('support_conversations')->insertGetId(['customer_id' => $customerId, 'subject' => trim($data['subject']),
                    'category' => $data['category'], 'priority' => $admin ? ($data['priority'] ?? 'normal') : 'normal',
                    'status' => $admin ? 'waiting_customer' : 'open', 'related_type' => $type, 'related_id' => $relatedId,
                    'request_id' => $data['request_id'], 'fingerprint' => $fingerprint, 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $messageId = $this->insertMessage($actor, $id, ['body' => $data['body'] ?? '', 'kind' => 'reply', 'request_id' => (string) Str::uuid()], $files, $admin, $paths);
                DB::table('support_conversations')->where('id', $id)->update($admin
                    ? ['last_admin_reply_id' => $messageId]
                    : ['last_customer_message_id' => $messageId, 'last_customer_at' => now()]);
                $this->audit($actor, $id, 'created', $messageId);
                if (! $admin) {
                    $this->notify($id, $messageId);
                }

                return $id;
            });
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    private function insertMessage(User $actor, int $id, array $data, array $files, bool $admin, array &$paths): int
    {
        $kind = $admin ? ($data['kind'] ?? 'reply') : 'reply';
        $reply = filled($data['reply_to'] ?? null) ? (int) $data['reply_to'] : null;
        if ($reply) {
            $target = DB::table('support_messages')->where('conversation_id', $id)->find($reply);
            if (! $target || $target->deleted_at !== null || $target->kind === 'event' || ($target->kind === 'note' && $kind !== 'note')) {
                throw ValidationException::withMessages(['reply_to' => 'Choose an available message in this conversation.']);
            }
        }
        $message = DB::table('support_messages')->insertGetId(['conversation_id' => $id, 'sender_id' => $actor->id,
            'sender_kind' => $admin ? 'admin' : 'customer', 'kind' => $kind, 'body' => trim($data['body'] ?? ''),
            'reply_to' => $reply, 'request_id' => $data['request_id'], 'fingerprint' => $this->fingerprint($actor, $data, $files),
            'created_at' => now(), 'updated_at' => now()]);
        foreach ($files as $file) {
            $uuid = (string) Str::uuid();
            $path = 'support-attachments/'.$uuid.'.'.$file->extension();
            $paths[] = $path;
            abort_unless(Storage::disk('local')->putFileAs('support-attachments', $file, basename($path)), 503);
            DB::table('support_attachments')->insert(['id' => $uuid, 'message_id' => $message, 'path' => $path, 'name' => $this->filename($file),
                'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $message;
    }

    public function send(User $actor, int $id, array $data, array $files = [], bool $admin = true): int
    {
        $this->authorize($actor, $id, $admin);
        $this->validateUploads($files);
        $paths = [];
        try {
            return DB::transaction(function () use ($actor, $id, $data, $files, $admin, &$paths): int {
                $c = DB::table('support_conversations')->where('id', $id)->lockForUpdate()->firstOrFail();
                if ($prior = DB::table('support_messages')->where('request_id', $data['request_id'])->first()) {
                    abort_unless($prior->conversation_id === $id && hash_equals($prior->fingerprint, $this->fingerprint($actor, $data, $files)), 409);

                    return $prior->id;
                }
                $message = $this->insertMessage($actor, $id, $data, $files, $admin, $paths);
                $values = ['last_activity_at' => now(), 'updated_at' => now(), 'revision' => $c->revision + 1];
                if (! $admin) {
                    $values += ['last_customer_message_id' => $message, 'last_customer_at' => now()];
                    if (in_array($c->status, ['resolved', 'waiting_customer'], true)) {
                        $values += ['status' => 'open', 'resolved_at' => null];
                    }
                    $this->notify($id, $message);
                } elseif (($data['kind'] ?? 'reply') === 'reply') {
                    $values += ['last_admin_reply_id' => $message, 'status' => 'waiting_customer', 'resolved_at' => null];
                    $this->resolveNotices($id);
                }
                DB::table('support_conversations')->where('id', $id)->update($values);
                $this->audit($actor, $id, ($data['kind'] ?? 'reply') === 'note' ? 'note_added' : 'replied', $message);

                return $message;
            });
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    public function deleteMessage(User $actor, int $id, int $messageId, bool $admin = true): void
    {
        $this->authorize($actor, $id, $admin);
        DB::transaction(function () use ($actor, $id, $messageId, $admin): void {
            $conversation = DB::table('support_conversations')->where('id', $id)->lockForUpdate()->firstOrFail();
            $message = DB::table('support_messages')->where('conversation_id', $id)->where('id', $messageId)->lockForUpdate()->first();
            abort_unless($message, 404);
            abort_unless($message->sender_kind === ($admin ? 'admin' : 'customer') && $message->sender_id === $actor->id && ($admin ? $message->kind !== 'event' : $message->kind === 'reply'), 403);
            if ($message->deleted_at !== null) {
                return;
            }
            DB::table('support_messages')->where('id', $messageId)->update(['body' => '', 'deleted_at' => now(), 'updated_at' => now()]);
            DB::table('support_conversations')->where('id', $id)->update(['revision' => $conversation->revision + 1, 'updated_at' => now()]);
            $this->audit($actor, $id, 'message_deleted', $messageId);
        });
    }

    public function update(User $actor, int $id, array $data): void
    {
        $this->authorize($actor, $id, true);
        DB::transaction(function () use ($actor, $id, $data): void {
            $c = DB::table('support_conversations')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($c->revision === (int) $data['revision'], 409, 'Conversation changed. Refresh and try again.');
            $changes = array_intersect_key($data, array_flip(['status', 'priority', 'category']));
            $changes = array_filter($changes, fn ($value, $key) => $value !== $c->$key, ARRAY_FILTER_USE_BOTH);
            if (! $changes) {
                return;
            }
            $changes['revision'] = $c->revision + 1;
            $changes['updated_at'] = now();
            $changes['last_activity_at'] = now();
            if (isset($changes['status'])) {
                $changes['resolved_at'] = $changes['status'] === 'resolved' ? now() : null;
                if ($changes['status'] === 'resolved') {
                    $this->resolveNotices($id);
                }
            }
            DB::table('support_conversations')->where('id', $id)->update($changes);
            $event = array_intersect_key($changes, array_flip(['status', 'category', 'priority']));
            $message = DB::table('support_messages')->insertGetId(['conversation_id' => $id, 'sender_id' => $actor->id, 'sender_kind' => 'system',
                'kind' => 'event', 'body' => '', 'event' => json_encode($event), 'request_id' => (string) Str::uuid(),
                'fingerprint' => hash('sha256', json_encode($event)), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, $id, 'updated', $message, $event);
        });
    }

    public function markRead(User $actor, int $id, int $through, bool $admin = true): void
    {
        $this->authorize($actor, $id, $admin);
        DB::transaction(function () use ($actor, $id, $through, $admin): void {
            DB::table('support_conversations')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($through > 0) {
                $query = DB::table('support_messages')->where('conversation_id', $id)->where('id', $through);
                if (! $admin) {
                    $query->where('kind', '!=', 'note');
                }
                abort_unless($query->exists(), 422);
            }
            $prior = (int) DB::table('support_reads')->where('conversation_id', $id)->where('user_id', $actor->id)->value('last_message_id');
            DB::table('support_reads')->upsert([['conversation_id' => $id, 'user_id' => $actor->id, 'last_message_id' => max($prior, $through),
                'created_at' => now(), 'updated_at' => now()]], ['conversation_id', 'user_id'], ['last_message_id', 'updated_at']);
            if ($admin) {
                $notices = DB::table('admin_notices')->where('target_type', 'support')->where('target_id', (string) $id)
                    ->where('context->message_id', '<=', $through)->pluck('id')->all();
                if ($notices) {
                    app(AdminNotifications::class)->change($actor, 'read', $notices);
                }
            }
        });
    }

    public function attachment(User $actor, int $id, string $attachment, bool $admin = true): object
    {
        $this->authorize($actor, $id, $admin);
        $query = DB::table('support_attachments as a')->join('support_messages as m', 'm.id', '=', 'a.message_id')
            ->where('a.id', $attachment)->where('m.conversation_id', $id)->whereNull('m.deleted_at');
        if (! $admin) {
            $query->where('m.kind', '!=', 'note');
        }

        return $query->select('a.*')->first() ?? abort(404);
    }

    public function paymentInformation(User $actor, PaymentTransaction $payment, string $message, string $requestId): int
    {
        $existing = DB::table('support_conversations')->where('customer_id', $payment->user_id)->where('related_type', 'payment')
            ->where('related_id', $payment->id)->where('status', '!=', 'resolved')->orderByDesc('id')->first();
        if ($existing) {
            $this->send($actor, $existing->id, ['kind' => 'reply', 'body' => $message, 'request_id' => $requestId]);

            return $existing->id;
        }

        return $this->create($actor, ['customer_id' => $payment->user_id,
            'subject' => 'Payment information: PYT-'.str_pad((string) $payment->id, 7, '0', STR_PAD_LEFT),
            'category' => 'payment', 'priority' => 'normal', 'related_type' => 'payment', 'related_id' => $payment->id,
            'body' => $message, 'request_id' => $requestId]);
    }

    private function resolveNotices(int $id): void
    {
        DB::table('admin_notices')->where('target_type', 'support')->where('target_id', (string) $id)->where('issue', 'attention')
            ->update(['issue' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
    }

    private function audit(User $actor, int $id, string $action, int $messageId, array $details = []): void
    {
        app(AuditTrail::class)->record('support', $action === 'message_deleted' ? $messageId.':deleted' : (string) $messageId, 'accounts', 'support.'.$action, 'success',
            $actor->id, 'support', (string) $id, $this->reference($id), ['message_id' => $messageId, ...$details]);
    }

    private function notify(int $id, int $messageId): void
    {
        $c = DB::table('support_conversations')->find($id);
        $user = User::findOrFail($c->customer_id);
        app(AdminNotifications::class)->publish('support:'.$id.':'.$messageId, ['category' => 'support', 'kind' => 'support_reply', 'priority' => $c->priority === 'urgent' ? 'critical' : 'warning',
            'target_type' => 'support', 'target_id' => (string) $id, 'reference' => $this->reference($id),
            'customer_id' => $user->id, 'customer_name' => mb_substr($user->name, 0, 120), 'customer_email' => $user->email, 'context' => ['message_id' => $messageId]]);
    }
}
