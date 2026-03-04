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
        $enabledSlugs = collect(['facebook', 'instagram', 'threads', 'youtube', 'bluesky', 'telegram', 'reddit'])
            ->filter(fn ($slug) => Setting::get("inbox_platform_{$slug}_enabled", true))
            ->values();

        $accountQuery = $user->is_admin
            ? SocialAccount::query()
            : $user->socialAccounts();

        $accountIds = $accountQuery
            ->whereHas('platform', fn ($q) => $q->whereIn('slug', $enabledSlugs))
            ->pluck('social_accounts.id');

        $query = InboxItem::whereIn('social_account_id', $accountIds)
            ->with(['socialAccount.platform', 'platform']);

        // Status filter (single value, mutually exclusive)
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', 'archived');
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

        // Platform filter (multi-select)
        $platforms = $request->input('platform', []);
        if (! is_array($platforms)) {
            $platforms = [$platforms];
        }
        $platforms = array_filter($platforms);
        if (! empty($platforms)) {
            $query->whereHas('platform', fn ($q) => $q->whereIn('slug', $platforms));
        }

        if ($accountId = $request->input('account')) {
            $query->where('social_account_id', $accountId);
        }

        // Fetch all filtered items and group by conversation
        $allItems = $query->orderBy('posted_at', 'asc')->get();

        // Group by conversation thread:
        // - DMs: group by chat/conversation (external_post_id = chat_id)
        // - Comments with parent_id: group with their root comment (parent_id = root's external_id)
        // - Top-level comments: group by their own external_id (so child items join them)
        $conversations = $allItems->groupBy(function ($item) {
            if ($item->type === 'dm') {
                return $item->social_account_id . ':dm:' . ($item->external_post_id ?: 'single:' . $item->id);
            }

            if ($item->parent_id) {
                return $item->social_account_id . ':thread:' . $item->parent_id;
            }

            return $item->social_account_id . ':thread:' . ($item->external_id ?: 'single:' . $item->id);
        });

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
            ];
        })->sortByDesc('latest_at')->values();

        // Manual pagination by conversations (15 per page)
        $page = (int) $request->input('page', 1);
        $perPage = 15;
        $conversations = new LengthAwarePaginator(
            $conversationList->forPage($page, $perPage)->values(),
            $conversationList->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $counts = [
            'total' => InboxItem::whereIn('social_account_id', $accountIds)->where('status', '!=', 'archived')->count(),
            'unread' => InboxItem::whereIn('social_account_id', $accountIds)->where('status', 'unread')->count(),
            'read' => InboxItem::whereIn('social_account_id', $accountIds)->where('status', 'read')->count(),
            'replied' => InboxItem::whereIn('social_account_id', $accountIds)->where('status', 'replied')->count(),
        ];

        $socialAccounts = ($user->is_admin
            ? SocialAccount::query()
            : $user->socialAccounts())
            ->whereHas('platform', fn ($q) => $q->whereIn('slug', $enabledSlugs))
            ->with('platform')
            ->orderBy('name')
            ->get();

        return view('inbox.index', compact('conversations', 'counts', 'socialAccounts', 'enabledSlugs'));
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
            'ids' => 'required|array|min:1|max:20',
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
                ]);
            }

            $results[] = ['id' => $item->id, 'success' => $result['success'], 'error' => $result['error'] ?? null];

            usleep(500000); // 500ms between sends
        }

        return response()->json(['results' => $results]);
    }

    public function sync(Request $request): JsonResponse
    {
        $syncService = app(InboxSyncService::class);

        if ($accountId = $request->input('account_id')) {
            $account = SocialAccount::with('platform')->findOrFail($accountId);
            $result = $syncService->syncAccount($account);
        } else {
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

        $language = $account->languages[0] ?? 'fr';
        $languageLabel = match ($language) {
            'fr' => 'français',
            'en' => 'anglais',
            'pt' => 'portugais',
            'es' => 'espagnol',
            'de' => 'allemand',
            'it' => 'italien',
            default => $language,
        };

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

        $prompt = "Réponds à ce {$typeLabel} en {$languageLabel}.\n\n";

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

        $prompt .= "{$typeLabel} de {$item->author_name} :\n\"{$item->content}\"";

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
