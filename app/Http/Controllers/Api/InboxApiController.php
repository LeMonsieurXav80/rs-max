<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InboxItem;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Inbox\InboxReplyGenerator;
use App\Services\Inbox\InboxReplyService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InboxApiController extends Controller
{
    private const PLATFORMS = ['facebook', 'instagram', 'threads', 'youtube', 'bluesky', 'telegram', 'twitter'];

    /** Read visibility follows the web inbox; answering requires an active account pivot. */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $query = $this->query($request->user(), $filters);
        $status = $filters['status'] ?? 'unanswered';
        if ($status === 'unanswered') {
            $this->unanswered($query);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $items = $query->paginate($filters['limit'] ?? 20);
        $writable = $request->user()->activeSocialAccounts()->pluck('social_accounts.id')->all();

        return response()->json([
            'items' => $items->getCollection()->map(fn ($item) => $this->format($item, in_array($item->social_account_id, $writable))),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /** Select a bounded lot, without generating or sending any reply in this request. */
    public function prepare(Request $request): JsonResponse
    {
        $filters = $this->filters($request, prepare: true);
        $writable = $request->user()->activeSocialAccounts()->pluck('social_accounts.id')->all();
        abort_if(array_diff($filters['accounts'] ?? [], $writable), 403, 'Compte non autorisé pour répondre.');

        $query = $this->query($request->user(), $filters)->whereIn('social_account_id', $writable);
        $this->unanswered($query);
        $available = (clone $query)->count();

        $missingIds = (clone $query)->reorder()
            ->whereHas('socialAccount', fn ($q) => $q->whereDoesntHave('persona', fn ($p) => $p
                ->where('is_active', true)->whereNotNull('system_prompt')->whereRaw("TRIM(system_prompt) <> ''")))
            ->distinct()->pluck('social_account_id');
        $missing = SocialAccount::whereIn('id', $missingIds)->get(['id', 'name'])->map(fn ($a) => [
            'account_id' => $a->id,
            'account_name' => $a->name,
            'reason' => 'Persona actif avec consignes requis.',
        ]);

        $query->whereHas('socialAccount.persona', fn ($q) => $q->where('is_active', true)
            ->whereNotNull('system_prompt')->whereRaw("TRIM(system_prompt) <> ''"));
        $eligible = (clone $query)->count();
        $items = $query->limit($filters['limit'])->get();

        return response()->json([
            'requested' => $filters['limit'],
            'selected' => $items->count(),
            'available' => $available,
            'eligible' => $eligible,
            'skipped_accounts' => $missing,
            'items' => $items->map(fn ($item) => $this->format($item, true)),
        ]);
    }

    /** Count the exact IDs of a cycle, or all messages in an explicit account/network scope. */
    public function status(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $data = $request->validate([
            'ids' => 'required_without_all:accounts,platforms|array|min:1|max:1000',
            'ids.*' => 'integer|distinct|min:1',
        ]);
        $filters['types'] ??= ['comment', 'reply', 'dm'];
        // New-only is a selection filter. A cycle must still include its items after they are sent.
        unset($filters['new_only']);
        $query = $this->query($request->user(), $filters, onlyEnabled: false)->withoutEagerLoads()->reorder();
        if (isset($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        }
        $empty = ['total' => 0, 'unprepared' => 0, 'prepared' => 0, 'scheduled' => 0, 'sent' => 0, 'failed' => 0, 'excluded' => 0];
        $counts = $empty;
        $platforms = [];
        $nextAt = $lastPlannedAt = $lastSentAt = null;
        $slugs = \App\Models\Platform::pluck('slug', 'id');
        $query->select(['id', 'platform_id', 'status', 'reply_content', 'reply_external_id', 'replied_at', 'reply_scheduled_at', 'reply_attempts'])
            ->chunkById(500, function ($items) use (&$counts, &$platforms, $empty, $slugs, &$nextAt, &$lastPlannedAt, &$lastSentAt) {
                foreach ($items as $item) {
                    $state = match (true) {
                        (bool) ($item->replied_at || $item->reply_external_id || $item->status === 'replied') => 'sent',
                        in_array($item->status, ['archived', 'ignored']) => 'excluded',
                        // On its last attempt, the scheduler keeps its reservation until the network answers.
                        (bool) $item->reply_scheduled_at => 'scheduled',
                        $item->status === 'reply_failed' || $item->reply_attempts >= 3 => 'failed',
                        filled($item->reply_content) => 'prepared',
                        in_array($item->status, ['read', 'unread']) => 'unprepared',
                        default => 'excluded',
                    };
                    $slug = $slugs[$item->platform_id];
                    $platforms[$slug] ??= $empty;
                    $counts['total']++;
                    $counts[$state]++;
                    $platforms[$slug]['total']++;
                    $platforms[$slug][$state]++;
                    if ($state === 'scheduled') {
                        $at = $item->reply_scheduled_at->toIso8601String();
                        $nextAt = $nextAt === null || $at < $nextAt ? $at : $nextAt;
                        $lastPlannedAt = $lastPlannedAt === null || $at > $lastPlannedAt ? $at : $lastPlannedAt;
                    }
                    if ($state === 'sent' && $item->replied_at) {
                        $at = $item->replied_at->toIso8601String();
                        $lastSentAt = $lastSentAt === null || $at > $lastSentAt ? $at : $lastSentAt;
                    }
                }
            });
        $finish = function ($counts) {
            $counts['pending'] = $counts['unprepared'] + $counts['prepared'] + $counts['scheduled'];
            $counts['complete'] = $counts['pending'] === 0;

            return $counts;
        };

        $summary = $finish($counts);
        if (isset($data['ids']) && count($data['ids']) !== $counts['total']) {
            $summary['complete'] = false;
        }

        return response()->json([
            'counts' => $summary,
            'by_platform' => array_map($finish, $platforms),
            'requested_ids' => isset($data['ids']) ? count($data['ids']) : null,
            'missing_or_inaccessible' => isset($data['ids']) ? count($data['ids']) - $counts['total'] : 0,
            'next_at' => $nextAt, 'last_planned_at' => $lastPlannedAt, 'last_sent_at' => $lastSentAt,
        ]);
    }

    public function suggest(Request $request, InboxItem $inboxItem, InboxReplyGenerator $generator): JsonResponse
    {
        $this->authorizeReply($request->user(), $inboxItem);
        abort_unless($this->isUnanswered($inboxItem), 409, 'Message déjà traité ou réponse déjà préparée.');

        $account = $inboxItem->socialAccount;
        $persona = $account->persona;
        abort_unless($persona?->is_active && filled($persona->system_prompt), 422, 'Ce compte doit avoir un persona actif avec ses consignes.');

        // The API explicitly promises the account persona, even when the web toggle is off.
        $reply = $generator->generate($inboxItem, $account, usePersona: true);
        abort_unless($reply, 422, 'Impossible de générer la réponse. Vérifiez la configuration IA.');

        return response()->json([
            'id' => $inboxItem->id,
            'reply_text' => $reply,
            'persona' => ['id' => $persona->id, 'name' => $persona->name],
        ]);
    }

    public function reply(Request $request, InboxItem $inboxItem, InboxReplyService $service): JsonResponse
    {
        $data = $request->validate([
            'reply_text' => 'required|string|max:5000',
            'dry_run' => 'sometimes|boolean',
            'scheduled_at' => 'nullable|date|after:now',
        ]);
        $this->authorizeReply($request->user(), $inboxItem);
        $text = trim($data['reply_text']);
        abort_if($text === '', 422, 'La réponse ne peut pas être vide.');

        // A retry of the same successful send is harmless; a different second reply is refused.
        $alreadyReplied = $inboxItem->replied_at || $inboxItem->reply_external_id || $inboxItem->status === 'replied';
        if ($alreadyReplied) {
            abort_unless($inboxItem->reply_content === $text, 409, 'Ce message a déjà reçu une réponse.');
        } else {
            abort_if($inboxItem->reply_attempts > 0, 409, 'Un envoi a déjà été tenté. Vérifiez son résultat avant de réessayer.');
            abort_unless(in_array($inboxItem->status, ['unread', 'read']), 409, 'Ce message est archivé, ignoré ou en échec.');
            abort_if(($inboxItem->reply_scheduled_at || filled($inboxItem->reply_content)) && $inboxItem->reply_content !== $text,
                409, 'Une autre réponse est déjà préparée pour ce message.');
        }

        if ($request->boolean('dry_run', true)) {
            return response()->json([
                'dry_run' => true,
                'sent' => false,
                'item' => $this->format($inboxItem, true),
                'reply_text' => $text,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'already_replied' => (bool) $alreadyReplied,
            ]);
        }

        $result = ! empty($data['scheduled_at']) && ! $alreadyReplied
            ? $service->schedule($inboxItem, $text, Carbon::parse($data['scheduled_at']))
            : $service->send($inboxItem, $text);

        return response()->json(['dry_run' => false, 'id' => $inboxItem->id] + $result,
            $result['success'] ? 200 : ($result['status_code'] ?? 502));
    }

    private function filters(Request $request, bool $prepare = false): array
    {
        return $request->validate([
            'platforms' => ($prepare ? 'required_without:accounts|' : 'sometimes|').'array|min:1|max:7',
            'platforms.*' => ['string', 'distinct', Rule::in(self::PLATFORMS)],
            'accounts' => ($prepare ? 'required_without:platforms|' : 'sometimes|').'array|min:1|max:100',
            'accounts.*' => 'integer|distinct|exists:social_accounts,id',
            'types' => 'sometimes|array|min:1|max:3',
            'types.*' => ['string', 'distinct', Rule::in(['comment', 'reply', 'dm'])],
            'limit' => ($prepare ? 'required|' : 'sometimes|').'integer|min:1|max:250',
            'new_only' => 'sometimes|boolean',
            'order' => ['sometimes', Rule::in(['oldest', 'newest'])],
            'status' => [$prepare ? 'prohibited' : 'sometimes', Rule::in(['unanswered', 'unread', 'read', 'replied', 'archived', 'ignored', 'reply_failed', 'all'])],
            'page' => 'sometimes|integer|min:1',
        ]);
    }

    private function query(User $user, array $filters, bool $onlyEnabled = true): Builder
    {
        $platforms = $onlyEnabled ? array_filter(self::PLATFORMS, fn ($slug) => (bool) Setting::get("inbox_platform_{$slug}_enabled", true)) : self::PLATFORMS;
        $query = InboxItem::query()->with(['socialAccount.platform', 'socialAccount.persona'])
            ->whereIn('social_account_id', $user->visibleSocialAccounts()->select('social_accounts.id'))
            ->whereHas('platform', fn ($q) => $q->whereIn('slug', $platforms))
            ->whereIn('type', $filters['types'] ?? ['comment', 'reply']);
        if (isset($filters['platforms'])) {
            $query->whereHas('platform', fn ($q) => $q->whereIn('slug', $filters['platforms']));
        }
        if (isset($filters['accounts'])) {
            $query->whereIn('social_account_id', $filters['accounts']);
        }
        if (filter_var($filters['new_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->newComments($query);
        }
        $direction = ($filters['order'] ?? 'oldest') === 'newest' ? 'desc' : 'asc';

        return $query->orderBy('posted_at', $direction)->orderBy('id', $direction);
    }

    /** Direct comments in conversations without any prior or pending answer. */
    private function newComments(Builder $query): void
    {
        $query->where('type', 'comment')
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhere('parent_id', ''))
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('inbox_items as handled')
                    ->whereColumn('handled.social_account_id', 'inbox_items.social_account_id')
                    ->where(function ($q) {
                        $q->where(function ($q) {
                            $q->whereNotNull('inbox_items.conversation_key')->where('inbox_items.conversation_key', '!=', '')
                                ->whereColumn('handled.conversation_key', 'inbox_items.conversation_key');
                        })->orWhereColumn('handled.parent_id', 'inbox_items.external_id');
                    })
                    ->where(function ($q) {
                        $q->whereNotNull('handled.replied_at')->orWhereNotNull('handled.reply_external_id')
                            ->orWhereNotNull('handled.reply_scheduled_at')->orWhere('handled.reply_attempts', '>', 0)
                            ->orWhere('handled.status', 'replied')
                            ->orWhere(fn ($q) => $q->whereNotNull('handled.reply_content')->where('handled.reply_content', '!=', ''))
                            ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('social_accounts as own')
                                ->whereColumn('own.id', 'inbox_items.social_account_id')
                                ->whereColumn('own.platform_account_id', 'handled.author_external_id'));
                    });
            });
    }

    private function unanswered(Builder $query): void
    {
        $query->whereIn('status', ['unread', 'read'])->whereNull('replied_at')->whereNull('reply_external_id')
            ->whereNull('reply_scheduled_at')->where('reply_attempts', 0)
            ->where(fn ($q) => $q->whereNull('reply_content')->orWhere('reply_content', ''))
            ->whereHas('socialAccount', fn ($q) => $q->where(fn ($a) => $a->whereNull('inbox_items.author_external_id')
                ->orWhereColumn('social_accounts.platform_account_id', '!=', 'inbox_items.author_external_id')));
    }

    private function isUnanswered(InboxItem $item): bool
    {
        return in_array($item->status, ['unread', 'read']) && ! $item->replied_at && ! $item->reply_external_id
            && ! $item->reply_scheduled_at && ! filled($item->reply_content) && (int) $item->reply_attempts === 0;
    }

    private function authorizeReply(User $user, InboxItem $item): void
    {
        abort_unless($user->activeSocialAccounts()->where('social_accounts.id', $item->social_account_id)->exists(), 403,
            'Compte non autorisé pour répondre.');
        $item->loadMissing(['socialAccount.platform', 'socialAccount.persona']);
        $slug = $item->socialAccount->platform->slug;
        abort_unless(in_array($slug, self::PLATFORMS) && Setting::get("inbox_platform_{$slug}_enabled", true), 422,
            'Réseau non disponible dans la messagerie.');
        abort_if($item->author_external_id && $item->author_external_id === $item->socialAccount->platform_account_id, 409,
            'Ce message a été écrit par le compte lui-même.');
    }

    private function format(InboxItem $item, bool $writable): array
    {
        $account = $item->socialAccount;
        $persona = $account->persona;

        return [
            'id' => $item->id,
            'type' => $item->type,
            'content' => $item->content,
            'author' => $item->author_name ?? $item->author_username,
            'platform' => $account->platform->slug,
            'account' => ['id' => $account->id, 'name' => $account->name],
            'persona' => $persona ? ['id' => $persona->id, 'name' => $persona->name, 'is_active' => $persona->is_active] : null,
            'status' => $item->status,
            'posted_at' => $item->posted_at?->toIso8601String(),
            'post_url' => $item->post_url,
            'reply_content' => $item->reply_content,
            'reply_external_id' => $item->reply_external_id,
            'replied_at' => $item->replied_at?->toIso8601String(),
            'reply_scheduled_at' => $item->reply_scheduled_at?->toIso8601String(),
            'can_reply' => $writable && $this->isUnanswered($item)
                && (! $item->author_external_id || $item->author_external_id !== $account->platform_account_id),
        ];
    }
}
