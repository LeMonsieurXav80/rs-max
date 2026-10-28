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
            'limit' => ($prepare ? 'required|' : 'sometimes|').'integer|min:1|max:100',
            'order' => ['sometimes', Rule::in(['oldest', 'newest'])],
            'status' => [$prepare ? 'prohibited' : 'sometimes', Rule::in(['unanswered', 'unread', 'read', 'replied', 'archived', 'ignored', 'reply_failed', 'all'])],
            'page' => 'sometimes|integer|min:1',
        ]);
    }

    private function query(User $user, array $filters): Builder
    {
        $platforms = array_filter(self::PLATFORMS, fn ($slug) => (bool) Setting::get("inbox_platform_{$slug}_enabled", true));
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
        $direction = ($filters['order'] ?? 'oldest') === 'newest' ? 'desc' : 'asc';

        return $query->orderBy('posted_at', $direction)->orderBy('id', $direction);
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
