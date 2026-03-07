<?php

namespace App\Http\Controllers;

use App\Models\InboxItem;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Only include accounts from platforms that are enabled in inbox settings
        $enabledSlugs = collect(['facebook', 'instagram', 'threads', 'youtube', 'bluesky', 'telegram', 'reddit', 'twitter'])
            ->filter(fn ($slug) => Setting::get("inbox_platform_{$slug}_enabled", true))
            ->values();

        $accountQuery = $user->isAdmin()
            ? SocialAccount::query()
            : $user->socialAccounts();

        $allAccountIds = $accountQuery
            ->whereHas('platform', fn ($q) => $q->whereIn('slug', $enabledSlugs))
            ->pluck('social_accounts.id');

        // Filter by selected accounts (multi-select)
        $selectedAccountIds = $request->input('accounts', []);
        if (! is_array($selectedAccountIds)) {
            $selectedAccountIds = [$selectedAccountIds];
        }
        $selectedAccountIds = array_map('intval', array_filter($selectedAccountIds));

        $accountIds = ! empty($selectedAccountIds)
            ? $allAccountIds->intersect($selectedAccountIds)->values()
            : $allAccountIds;

        $query = InboxItem::whereIn('social_account_id', $accountIds)
            ->with(['socialAccount.platform', 'platform']);

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

        // Fetch filtered items
        $filteredItems = $query->orderBy('posted_at', 'asc')->get();

        // When status is filtered, fetch related conversation items for context.
        $allItems = $filteredItems;
        if ($statusIsFiltered && $filteredItems->isNotEmpty()) {
            $conversationKeys = $filteredItems->pluck('conversation_key')->filter()->unique()->values();

            $relatedItems = InboxItem::whereIn('social_account_id', $accountIds)
                ->whereNotIn('status', ['archived', 'ignored'])
                ->whereIn('conversation_key', $conversationKeys)
                ->with(['socialAccount.platform', 'platform'])
                ->orderBy('posted_at', 'asc')
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

            return $item->social_account_id . ':' . $key;
        });

        // When status is filtered, only keep conversations that contain at least one
        // item from the original filtered set (context items alone don't qualify).
        $filteredIds = $statusIsFiltered ? $filteredItems->pluck('id')->flip() : null;

        $conversationList = $conversations->map(function ($items, $key) {
            $first = $items->first();
            $latest = $items->sortByDesc('posted_at')->first();

            return (object) [
                'key' => $key,
                'items' => $items->sortBy('posted_at')->values(),
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
        });

        if ($filteredIds) {
            $conversationList = $conversationList->filter(function ($convo) use ($filteredIds) {
                return $convo->items->contains(fn ($item) => $filteredIds->has($item->id));
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
                $ownPlatformId = $accountPlatformIds[$convo->socialAccount->id] ?? null;

                // Exclude conversations where the latest message is from the account owner
                return ! $ownPlatformId || (string) ($convo->latest_author_external_id ?? '') !== (string) $ownPlatformId;
            })->values();

            // "new" = never replied in this conversation; "followup" = replied before, new message since
            $wantNew = $status === 'new';
            $conversationList = $conversationList->filter(function ($convo) use ($wantNew) {
                $hasReply = $convo->items->contains(fn ($i) => $i->status === 'replied' || $i->reply_content);

                return $wantNew ? ! $hasReply : $hasReply;
            })->values();
        }

        // Manual pagination by conversations
        $page = (int) $request->input('page', 1);
        $perPage = (int) ($request->input('per_page') ?: Setting::get('inbox_per_page', 15));
        $conversations = new LengthAwarePaginator(
            $conversationList->forPage($page, $perPage)->values(),
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

        $socialAccounts = ($user->isAdmin()
            ? SocialAccount::query()
            : $user->socialAccounts())
            ->whereHas('platform', fn ($q) => $q->whereIn('slug', $enabledSlugs))
            ->with('platform')
            ->orderBy('name')
            ->get();

        return view('inbox.index', compact('conversations', 'counts', 'socialAccounts', 'enabledSlugs', 'scheduledInfo', 'selectedAccountIds'));
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
                'status' => 'read',
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

        $account = $inboxItem->socialAccount()->with('platform')->first();
        $syncService = app(InboxSyncService::class);
        $service = $syncService->getServiceForPlatform($account->platform->slug);

        if (! $service) {
            return response()->json(['success' => false, 'error' => 'Service non disponible'], 422);
        }

        $result = $service->sendReply($account, $inboxItem, $validated['reply_text']);

        if ($result['success']) {
            $inboxItem->update([
                'status' => 'replied',
                'reply_content' => $validated['reply_text'],
                'reply_external_id' => $result['external_id'],
                'replied_at' => now(),
                'reply_scheduled_at' => null,
            ]);
        }

        return response()->json($result);
    }

    public function aiSuggest(Request $request, InboxItem $inboxItem): JsonResponse
    {
        $account = $inboxItem->socialAccount()->with(['platform', 'persona'])->first();

        $reply = $this->generateAiReplyText($inboxItem, $account);

        if (! $reply) {
            return response()->json(['error' => 'Impossible de générer une réponse. Vérifiez la clé API et la persona du compte.'], 422);
        }

        return response()->json(['reply' => $reply]);
    }

    public function bulkAiReply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:250',
            'ids.*' => 'integer|exists:inbox_items,id',
        ]);

        $items = InboxItem::with(['socialAccount.platform', 'socialAccount.persona'])
            ->whereIn('id', $validated['ids'])
            ->get();

        $suggestions = [];

        foreach ($items as $item) {
            $account = $item->socialAccount;
            $reply = $this->generateAiReplyText($item, $account);

            $suggestions[] = [
                'id' => $item->id,
                'content' => $item->content,
                'author' => $item->author_name ?? $item->author_username,
                'platform' => $account->platform->slug,
                'reply' => $reply,
                'error' => $reply ? null : 'Impossible de générer',
            ];

            usleep(500000); // 500ms between API calls
        }

        return response()->json(['suggestions' => $suggestions]);
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

                $item->update([
                    'reply_content' => $itemData['reply_text'],
                    'reply_scheduled_at' => $scheduledAt,
                    'reply_attempts' => 0,
                    'status' => $item->status === 'reply_failed' ? 'read' : $item->status,
                ]);

                $results[] = ['id' => $item->id, 'success' => true, 'scheduled_at' => $scheduledAt->toDateTimeString()];
            }

            return response()->json(['results' => $results, 'scheduled' => true, 'spread_minutes' => $spreadMinutes]);
        }

        // No spread: send immediately
        $syncService = app(InboxSyncService::class);
        $results = [];

        foreach ($validated['items'] as $itemData) {
            $item = InboxItem::with('socialAccount.platform')->find($itemData['id']);

            if (! $item) {
                $results[] = ['id' => $itemData['id'], 'success' => false, 'error' => 'Not found'];

                continue;
            }

            $account = $item->socialAccount;
            $service = $syncService->getServiceForPlatform($account->platform->slug);

            if (! $service) {
                $results[] = ['id' => $item->id, 'success' => false, 'error' => 'Service non disponible'];

                continue;
            }

            $result = $service->sendReply($account, $item, $itemData['reply_text']);

            if ($result['success']) {
                $item->update([
                    'status' => 'replied',
                    'reply_content' => $itemData['reply_text'],
                    'reply_external_id' => $result['external_id'],
                    'replied_at' => now(),
                    'reply_scheduled_at' => null,
                ]);
            }

            $results[] = ['id' => $item->id, 'success' => $result['success'], 'error' => $result['error'] ?? null];

            usleep(500000); // 500ms between sends
        }

        return response()->json(['results' => $results]);
    }

    public function scheduledStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        $accountIds = ($user->isAdmin() ? SocialAccount::query() : $user->socialAccounts())
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

    private function generateAiReplyText(InboxItem $item, SocialAccount $account): ?string
    {
        $apiKey = Setting::getEncrypted('openai_api_key');

        if (! $apiKey) {
            return null;
        }

        $persona = $account->persona;

        if (! $persona) {
            return null;
        }

        $typeLabel = match ($item->type) {
            'dm' => 'message privé',
            'comment' => 'commentaire',
            'reply' => 'réponse',
            default => 'message',
        };

        // Build conversation context: find other items in the same thread
        $threadItems = collect();
        if ($item->type === 'dm' && $item->external_post_id) {
            $threadItems = InboxItem::where('social_account_id', $item->social_account_id)
                ->where('external_post_id', $item->external_post_id)
                ->where('type', 'dm')
                ->where('id', '!=', $item->id)
                ->orderBy('posted_at', 'asc')
                ->limit(10)
                ->get();
        } elseif ($item->parent_id || $item->external_id) {
            $rootId = $item->parent_id ?: $item->external_id;
            $threadItems = InboxItem::where('social_account_id', $item->social_account_id)
                ->where(function ($q) use ($rootId, $item) {
                    $q->where('external_id', $rootId)      // the root comment
                      ->orWhere('parent_id', $rootId);      // other replies to root
                })
                ->where('id', '!=', $item->id)
                ->orderBy('posted_at', 'asc')
                ->limit(10)
                ->get();
        }

        $prompt = "N'entoure JAMAIS ta réponse de guillemets.\n"
            . "Adapte le style de ta réponse au message reçu : si le message ne contient que des emojis, réponds uniquement avec des emojis. Si le message mélange texte et emojis, réponds avec du texte et des emojis dans des proportions similaires. Si le message est uniquement du texte, réponds avec du texte (tu peux ajouter un emoji en fin de message).\n\n";

        if ($threadItems->isNotEmpty()) {
            $prompt .= "Contexte de la conversation :\n";
            foreach ($threadItems as $ti) {
                $author = $ti->author_name ?? $ti->author_username ?? 'Inconnu';
                $prompt .= "- {$author} : \"{$ti->content}\"\n";
                if ($ti->reply_content) {
                    $prompt .= "- [Notre réponse] : \"{$ti->reply_content}\"\n";
                }
            }
            $prompt .= "\n";
        }

        $prompt .= "{$typeLabel} de {$item->author_name} :\n\"{$item->content}\"\n\n"
            . "IMPORTANT: You MUST reply in the same language as the message above. If the message is in English, reply in English. If in Italian, reply in Italian. Match the language exactly.";

        // Build system prompt: persona + inbox reply wrapper
        $replyWrapper = Setting::get('inbox_reply_prompt', '');
        $systemPrompt = $persona->system_prompt;
        if ($replyWrapper) {
            $systemPrompt .= "\n\n" . $replyWrapper;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => Setting::get('ai_model_inbox', Setting::get('ai_model_text', 'gpt-4o-mini')),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 500,
            ]);

            if ($response->successful()) {
                return trim($response->json('choices.0.message.content', ''));
            }
        } catch (\Throwable $e) {
            Log::error('InboxController: AI reply generation failed', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
