<?php

namespace App\Http\Controllers;

use App\Models\InboxItem;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxReplyGenerator;
use App\Services\Inbox\InboxReplyService;
use App\Services\Inbox\InboxSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Only include accounts from platforms that are enabled in inbox settings
        $enabledSlugs = collect(['facebook', 'instagram', 'threads', 'youtube', 'bluesky', 'telegram', 'twitter'])
            ->filter(fn ($slug) => Setting::get("inbox_platform_{$slug}_enabled", true))
            ->values();

        $accountQuery = $user->seesAllContent()
            ? SocialAccount::query()
            : $user->socialAccounts();

        $allAccountIds = $accountQuery
            ->whereHas('platform', fn ($q) => $q->whereIn('slug', $enabledSlugs))
            ->pluck('social_accounts.id');

        // Filter by selected accounts (multi-select), fall back to saved defaults
        $selectedAccountIds = $request->input('accounts', []);
        if (! is_array($selectedAccountIds)) {
            $selectedAccountIds = [$selectedAccountIds];
        }
        $selectedAccountIds = array_map('intval', array_filter($selectedAccountIds));

        if (empty($selectedAccountIds)) {
            $selectedAccountIds = array_map('intval', $user->default_accounts ?? []);
        }

        $accountIds = ! empty($selectedAccountIds)
            ? $allAccountIds->intersect($selectedAccountIds)->values()
            : $allAccountIds;

        $query = InboxItem::whereIn('social_account_id', $accountIds);

        // Status filter (single value, mutually exclusive)
        $status = $request->input('status') ?? '';
        $statusIsFiltered = false;
        if (in_array($status, ['new', 'followup'])) {
            $query->whereIn('status', ['unread', 'read']);
            $statusIsFiltered = true;
        } elseif ($status !== '') {
            $query->where('status', $status);
            $statusIsFiltered = true;
        } else {
            $query->whereNotIn('status', ['archived', 'ignored']);
        }

        // Type filter (multi-select)
        $types = $request->input('type', []);
        if (! is_array($types)) {
            $types = [$types];
        }
        $types = array_filter($types);
        if (! empty($types)) {
            $query->whereIn('type', $types);
        }

        // Le regroupement en conversations, les filtres « new »/« followup » et le
        // tri se font en PHP : ils portent sur la conversation entière, pas sur une
        // ligne. Il faut donc balayer TOUTES les lignes des comptes selectionnes
        // avant de pouvoir en afficher 15 — ce qui a fait tomber /inbox en 500
        // (memory_limit 256 Mo creve a ~47 000 lignes pour 8 comptes, le 18/10/2026).
        //
        // D'ou deux passes. Celle-ci ne lit que les colonnes necessaires au
        // regroupement, sans modele Eloquent (`toBase()`) ni relation chargee :
        // ~10 scalaires par ligne au lieu d'un objet complet avec son compte et sa
        // plateforme. Les modeles entiers ne sont hydrates qu'a la seconde passe,
        // pour la seule page affichee.
        //
        // `reply_content` est volontairement reduit a un booleen `has_reply` : la
        // colonne est un TEXT, et seule sa presence sert ici.
        $colonnesDeRegroupement = [
            'id', 'social_account_id', 'conversation_key', 'external_id',
            'parent_id', 'reply_external_id', 'posted_at', 'status',
            'author_external_id',
        ];
        $presenceDeReponse = "CASE WHEN reply_content IS NULL OR reply_content = '' THEN 0 ELSE 1 END as has_reply";

        $filteredItems = (clone $query)
            ->select($colonnesDeRegroupement)
            ->selectRaw($presenceDeReponse)
            ->orderBy('posted_at', 'asc')
            ->toBase()
            ->get();

        // When status is filtered, fetch related conversation items for context.
        $allItems = $filteredItems;
        if ($statusIsFiltered && $filteredItems->isNotEmpty()) {
            $conversationKeys = $filteredItems->pluck('conversation_key')->filter()->unique()->values();

            $relatedItems = InboxItem::whereIn('social_account_id', $accountIds)
                ->whereNotIn('status', ['archived', 'ignored'])
                ->whereIn('conversation_key', $conversationKeys)
                ->select($colonnesDeRegroupement)
                ->selectRaw($presenceDeReponse)
                ->orderBy('posted_at', 'asc')
                ->toBase()
                ->get();

            $allItems = $filteredItems->merge($relatedItems)->unique('id')->sortBy('posted_at')->values();
        }

        // Build reply chain map: when someone replies to OUR reply, merge into original conversation.
        // Maps reply_external_id → conversation_key of the item we replied to.
        $replyExternalToConvoKey = $allItems
            ->filter(fn ($i) => $i->reply_external_id && $i->conversation_key)
            ->pluck('conversation_key', 'reply_external_id');

        $conversations = $allItems->groupBy(function ($item) use ($replyExternalToConvoKey) {
            $key = $item->conversation_key ?? $item->external_id ?? (string) $item->id;

            // If this item's parent_id points to our reply, merge into the original conversation
            if ($item->parent_id && $replyExternalToConvoKey->has($item->parent_id)) {
                $key = $replyExternalToConvoKey->get($item->parent_id);
            }

            return $item->social_account_id.':'.$key;
        });

        // When status is filtered, only keep conversations that contain at least one
        // item from the original filtered set (context items alone don't qualify).
        $filteredIds = $statusIsFiltered ? $filteredItems->pluck('id')->flip() : null;

        // Resume minimal par conversation : juste de quoi filtrer, trier et
        // paginer. Tout ce que la vue affiche est reconstruit plus bas, sur la
        // seule page retenue.
        $conversationList = $conversations->map(function ($items, $key) {
            $latest = $items->sortByDesc('posted_at')->first();

            return (object) [
                'key' => $key,
                'item_ids' => $items->pluck('id')->all(),
                'social_account_id' => $latest->social_account_id,
                'latest_at' => $latest->posted_at,
                'latest_author_external_id' => $latest->author_external_id,
                'has_reply' => $items->contains(fn ($i) => $i->status === 'replied' || (int) $i->has_reply === 1),
            ];
        });

        if ($filteredIds) {
            $conversationList = $conversationList->filter(function ($convo) use ($filteredIds) {
                return collect($convo->item_ids)->contains(fn ($id) => $filteredIds->has($id));
            });
        }

        $conversationList = $conversationList->sortByDesc('latest_at')->values();

        // For unreplied-type filters: exclude conversations where the latest message
        // is from the account owner (we already replied, no new message from others)
        if (in_array($status, ['new', 'followup'])) {
            // Use platform_account_id for reliable owner detection (name matching is fragile)
            $accountPlatformIds = SocialAccount::whereIn('id', $accountIds)
                ->pluck('platform_account_id', 'id');

            $conversationList = $conversationList->filter(function ($convo) use ($accountPlatformIds) {
                $ownPlatformId = $accountPlatformIds[$convo->social_account_id] ?? null;

                // Exclude conversations where the latest message is from the account owner
                return ! $ownPlatformId || (string) ($convo->latest_author_external_id ?? '') !== (string) $ownPlatformId;
            })->values();

            // "new" = never replied in this conversation; "followup" = replied before, new message since
            $wantNew = $status === 'new';
            $conversationList = $conversationList->filter(
                fn ($convo) => $wantNew ? ! $convo->has_reply : $convo->has_reply
            )->values();
        }

        // Manual pagination by conversations
        $page = (int) $request->input('page', 1);
        $perPage = (int) ($request->input('per_page') ?: Setting::get('inbox_per_page', 15));
        $pageDeResumes = $conversationList->forPage($page, $perPage)->values();

        // Seconde passe : les modeles complets, pour cette page uniquement. Une
        // requete pour tous les messages de la page, relations comprises — jamais
        // une par conversation.
        $modeles = InboxItem::with(['socialAccount.platform', 'platform'])
            ->whereIn('id', $pageDeResumes->flatMap(fn ($c) => $c->item_ids)->all())
            ->get()
            ->keyBy('id');

        $conversationsDeLaPage = $pageDeResumes->map(function ($resume) use ($modeles) {
            $items = collect($resume->item_ids)
                ->map(fn ($id) => $modeles->get($id))
                ->filter()
                ->sortBy('posted_at')
                ->values();

            // Un message supprime entre les deux passes laisserait une
            // conversation vide : on l'ecarte plutot que de rendre un objet creux.
            if ($items->isEmpty()) {
                return null;
            }

            $first = $items->first();
            $latest = $items->sortByDesc('posted_at')->first();

            return (object) [
                'key' => $resume->key,
                'items' => $items,
                'platform' => $first->platform,
                'socialAccount' => $first->socialAccount,
                'type' => $first->type,
                'latest_at' => $latest->posted_at,
                'unread_count' => $items->where('status', 'unread')->count(),
                'total_count' => $items->count(),
                'post_url' => $first->post_url,
                'external_post_id' => $first->external_post_id,
                'latest_author' => $latest->author_name ?? $latest->author_username,
                'latest_author_external_id' => $latest->author_external_id,
            ];
        })->filter()->values();

        $conversations = new LengthAwarePaginator(
            $conversationsDeLaPage,
            $conversationList->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $counts = [
            'total' => InboxItem::whereIn('social_account_id', $accountIds)->whereNotIn('status', ['archived', 'ignored'])->count(),
            'unreplied' => InboxItem::whereIn('social_account_id', $accountIds)->whereIn('status', ['unread', 'read'])->count(),
            'replied' => InboxItem::whereIn('social_account_id', $accountIds)->where('status', 'replied')->count(),
            'ignored' => InboxItem::whereIn('social_account_id', $accountIds)->where('status', 'ignored')->count(),
        ];

        // Scheduled replies progress
        $scheduledPending = InboxItem::whereIn('social_account_id', $accountIds)
            ->whereNotNull('reply_scheduled_at')
            ->whereNull('replied_at')
            ->whereNotIn('status', ['replied', 'reply_failed'])
            ->where('reply_attempts', '<', 3)
            ->orderBy('reply_scheduled_at', 'asc')
            ->get(['id', 'reply_scheduled_at']);

        // Failed replies (status or exhausted attempts)
        $failedItems = InboxItem::whereIn('social_account_id', $accountIds)
            ->where(fn ($q) => $q->where('status', 'reply_failed')->orWhere('reply_attempts', '>=', 3))
            ->whereNull('replied_at')
            ->select('id', 'author_name', 'author_username', 'reply_content', 'conversation_key')
            ->get();

        $scheduledInfo = null;
        if ($scheduledPending->isNotEmpty() || $failedItems->isNotEmpty()) {
            $scheduledInfo = [
                'pending' => $scheduledPending->count(),
                'next_at' => $scheduledPending->first()?->reply_scheduled_at,
                'last_at' => $scheduledPending->last()?->reply_scheduled_at,
                'failed' => $failedItems->count(),
                'failed_items' => $failedItems->map(fn ($i) => [
                    'id' => $i->id,
                    'author' => $i->author_name ?: $i->author_username ?: 'Inconnu',
                    'reply' => \Str::limit($i->reply_content, 60),
                ])->values(),
            ];
        }

        $socialAccounts = ($user->seesAllContent()
            ? SocialAccount::query()
            : $user->socialAccounts())
            ->whereHas('platform', fn ($q) => $q->whereIn('slug', $enabledSlugs))
            ->with('platform')
            ->orderBy('name')
            ->get();

        $accountGroups = $user->accountGroups()->with('socialAccounts')->get();

        return view('inbox.index', compact('conversations', 'counts', 'socialAccounts', 'accountGroups', 'enabledSlugs', 'scheduledInfo', 'selectedAccountIds'));
    }

    public function markRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:inbox_items,id',
        ]);

        InboxItem::whereIn('id', $validated['ids'])
            ->where('status', 'unread')
            ->update(['status' => 'read']);

        return response()->json(['success' => true]);
    }

    public function archive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:inbox_items,id',
        ]);

        InboxItem::whereIn('id', $validated['ids'])->update(['status' => 'archived']);

        return response()->json(['success' => true]);
    }

    public function ignore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:inbox_items,id',
        ]);

        InboxItem::whereIn('id', $validated['ids'])->update(['status' => 'ignored']);

        return response()->json(['success' => true]);
    }

    public function dismissFailed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:inbox_items,id',
        ]);

        InboxItem::whereIn('id', $validated['ids'])
            ->where(fn ($q) => $q->where('status', 'reply_failed')->orWhere('reply_attempts', '>=', 3))
            ->update([
                'status' => 'ignored',
                'reply_content' => null,
                'reply_scheduled_at' => null,
                'reply_attempts' => 0,
            ]);

        return response()->json(['success' => true]);
    }

    public function reply(Request $request, InboxItem $inboxItem): JsonResponse
    {
        $validated = $request->validate([
            'reply_text' => 'required|string|max:5000',
        ]);

        return response()->json(app(InboxReplyService::class)->send($inboxItem, $validated['reply_text']));
    }

    public function aiSuggest(Request $request, InboxItem $inboxItem): JsonResponse
    {
        $account = $inboxItem->socialAccount()->with(['platform', 'persona'])->first();

        $reply = app(InboxReplyGenerator::class)->generate($inboxItem, $account);

        if (! $reply) {
            return response()->json(['error' => 'Impossible de générer une réponse. Vérifiez la clé API OpenAI'.(Setting::get('inbox_use_persona', true) ? ' et la persona du compte' : '').'.'], 422);
        }

        return response()->json(['reply' => $reply]);
    }

    /**
     * Return metadata for selected inbox items so the front-end can open
     * the bulk modal immediately and then call /inbox/{id}/ai-suggest
     * one item at a time. Avoids HTTP timeouts on large batches.
     */
    public function bulkAiPrepare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:250',
            'ids.*' => 'integer|exists:inbox_items,id',
        ]);

        $items = InboxItem::with('socialAccount.platform')
            ->whereIn('id', $validated['ids'])
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'content' => $item->content,
                'author' => $item->author_name ?? $item->author_username,
                'platform' => $item->socialAccount->platform->slug,
                'reply' => null,
                'error' => null,
                'loading' => true,
            ])
            ->values();

        return response()->json(['items' => $items]);
    }

    public function bulkSend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:inbox_items,id',
            'items.*.reply_text' => 'required|string|max:5000',
            'spread_minutes' => 'nullable|integer|min:0|max:1440',
        ]);

        $spreadMinutes = (int) ($validated['spread_minutes'] ?? 0);
        $itemCount = count($validated['items']);

        // If spread is set, schedule replies over the period instead of sending now
        if ($spreadMinutes > 0 && $itemCount > 0) {
            $intervalSeconds = ($spreadMinutes * 60) / $itemCount;
            $results = [];

            foreach ($validated['items'] as $i => $itemData) {
                $item = InboxItem::find($itemData['id']);

                if (! $item) {
                    $results[] = ['id' => $itemData['id'], 'success' => false, 'error' => 'Not found'];

                    continue;
                }

                $scheduledAt = now()->addSeconds((int) ($intervalSeconds * $i));

                $result = app(InboxReplyService::class)->schedule($item, $itemData['reply_text'], $scheduledAt);
                $results[] = ['id' => $item->id] + $result;
            }

            return response()->json(['results' => $results, 'scheduled' => true, 'spread_minutes' => $spreadMinutes]);
        }

        // No spread: send immediately
        $results = [];

        foreach ($validated['items'] as $itemData) {
            $item = InboxItem::with('socialAccount.platform')->find($itemData['id']);

            if (! $item) {
                $results[] = ['id' => $itemData['id'], 'success' => false, 'error' => 'Not found'];

                continue;
            }

            $result = app(InboxReplyService::class)->send($item, $itemData['reply_text']);
            $results[] = ['id' => $item->id, 'success' => $result['success'], 'error' => $result['error'] ?? null];

            usleep(500000); // 500ms between sends
        }

        return response()->json(['results' => $results]);
    }

    public function scheduledStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        $accountIds = ($user->seesAllContent() ? SocialAccount::query() : $user->socialAccounts())
            ->pluck('social_accounts.id');

        $pending = InboxItem::whereIn('social_account_id', $accountIds)
            ->whereNotNull('reply_scheduled_at')
            ->whereNull('replied_at')
            ->whereNotIn('status', ['replied', 'reply_failed'])
            ->where('reply_attempts', '<', 3)
            ->orderBy('reply_scheduled_at', 'asc')
            ->get(['id', 'reply_scheduled_at']);

        $failed = InboxItem::whereIn('social_account_id', $accountIds)
            ->where(fn ($q) => $q->where('status', 'reply_failed')->orWhere('reply_attempts', '>=', 3))
            ->whereNull('replied_at')
            ->count();

        if ($pending->isEmpty() && $failed === 0) {
            return response()->json(['pending' => 0, 'failed' => 0]);
        }

        return response()->json([
            'pending' => $pending->count(),
            'next_at' => $pending->first()?->reply_scheduled_at,
            'last_at' => $pending->last()?->reply_scheduled_at,
            'failed' => $failed,
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $syncService = app(InboxSyncService::class);

        if ($accountId = $request->input('account_id')) {
            $account = SocialAccount::with('platform')->findOrFail($accountId);
            $result = $syncService->syncAccount($account);
        } elseif ($platforms = $request->input('platforms')) {
            Log::info('InboxController: syncing specific platforms', ['platforms' => $platforms]);
            $result = $syncService->syncPlatforms((array) $platforms);
        } else {
            Log::info('InboxController: syncing all platforms');
            $result = $syncService->syncAll();
        }

        return response()->json($result);
    }
}
