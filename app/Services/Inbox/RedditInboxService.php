<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RedditInboxService implements PlatformInboxInterface
{
    private const AUTH_URL = 'https://www.reddit.com/api/v1/access_token';

    private const API_BASE = 'https://oauth.reddit.com';

    private const USER_AGENT = 'server:rs-max:v1.0.0 (by /u/%s)';

    public function fetchInbox(SocialAccount $account, ?Carbon $since = null): Collection
    {
        $items = collect();
        $credentials = $account->credentials;

        // Skip app records (they don't have a subreddit)
        if (($credentials['type'] ?? '') === 'app') {
            return $items;
        }

        $accessToken = $this->authenticate($credentials);
        if (! $accessToken) {
            Log::warning('RedditInboxService: authentication failed', ['account_id' => $account->id]);

            return $items;
        }

        $userAgent = sprintf(self::USER_AGENT, $credentials['username'] ?? 'unknown');

        try {
            // Fetch inbox (comment replies + DMs)
            $response = Http::withToken($accessToken)
                ->withHeaders(['User-Agent' => $userAgent])
                ->get(self::API_BASE . '/message/inbox', [
                    'limit' => 100,
                    'mark' => 'false',
                ]);

            if (! $response->successful()) {
                Log::warning('RedditInboxService: inbox fetch failed', ['status' => $response->status()]);

                return $items;
            }

            $children = $response->json('data.children', []);

            foreach ($children as $child) {
                $data = $child['data'] ?? [];
                $kind = $child['kind'] ?? '';

                $createdAt = isset($data['created_utc']) ? Carbon::createFromTimestamp($data['created_utc']) : null;

                $type = $kind === 't4' ? 'dm' : 'comment';
                $context = $data['context'] ?? null;

                $externalId = $data['name'] ?? $data['id'] ?? '';
                $parentId = $data['parent_id'] ?? null;

                if ($type === 'dm') {
                    $conversationKey = "dm:{$externalId}";
                } else {
                    $conversationKey = $parentId ?? $externalId;
                }

                $items->push([
                    'type' => $type,
                    'external_id' => $externalId,
                    'external_post_id' => $data['link_id'] ?? null,
                    'parent_id' => $parentId,
                    'conversation_key' => $conversationKey,
                    'author_name' => $data['author'] ?? null,
                    'author_username' => $data['author'] ?? null,
                    'content' => $data['body'] ?? null,
                    'post_url' => $context ? "https://reddit.com{$context}" : null,
                    'posted_at' => $createdAt,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('RedditInboxService: fetchInbox failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    public function sendReply(SocialAccount $account, InboxItem $item, string $replyText): array
    {
        try {
            $credentials = $account->credentials;
            $accessToken = $this->authenticate($credentials);

            if (! $accessToken) {
                return ['success' => false, 'external_id' => null, 'error' => 'Authentication failed'];
            }

            $userAgent = sprintf(self::USER_AGENT, $credentials['username'] ?? 'unknown');

            if ($item->type === 'dm') {
                return $this->sendDMReply($accessToken, $userAgent, $item, $replyText);
            }

            // Comment reply
            $response = Http::withToken($accessToken)
                ->withHeaders(['User-Agent' => $userAgent])
                ->asForm()
                ->post(self::API_BASE . '/api/comment', [
                    'api_type' => 'json',
                    'thing_id' => $item->external_id,
                    'text' => $replyText,
                ]);

            $data = $response->json();
            $errors = $data['json']['errors'] ?? [];

            if (empty($errors) && $response->successful()) {
                $replyName = $data['json']['data']['things'][0]['data']['name'] ?? null;

                return ['success' => true, 'external_id' => $replyName, 'error' => null];
            }

            $error = ! empty($errors) ? collect($errors)->map(fn ($e) => implode(': ', $e))->implode(', ') : 'Failed to reply';

            return ['success' => false, 'external_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::error('RedditInboxService: sendReply failed', [
                'account_id' => $account->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendDMReply(string $accessToken, string $userAgent, InboxItem $item, string $replyText): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders(['User-Agent' => $userAgent])
            ->asForm()
            ->post(self::API_BASE . '/api/compose', [
                'api_type' => 'json',
                'to' => $item->author_username,
                'subject' => 're: message',
                'text' => $replyText,
            ]);

        if ($response->successful()) {
            return ['success' => true, 'external_id' => null, 'error' => null];
        }

        return ['success' => false, 'external_id' => null, 'error' => 'Failed to send DM'];
    }

    private function authenticate(array $credentials): ?string
    {
        $clientId = $credentials['client_id'] ?? '';
        $clientSecret = $credentials['client_secret'] ?? '';
        $username = $credentials['username'] ?? '';
        $password = $credentials['password'] ?? '';
        $userAgent = sprintf(self::USER_AGENT, $username);

        $response = Http::withBasicAuth($clientId, $clientSecret)
            ->withHeaders(['User-Agent' => $userAgent])
            ->asForm()
            ->post(self::AUTH_URL, [
                'grant_type' => 'password',
                'username' => $username,
                'password' => $password,
            ]);

        if ($response->successful() && $response->json('access_token')) {
            return $response->json('access_token');
        }

        return null;
    }
}
