<?php

namespace App\Http\Controllers;

use App\Models\Platform;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FacebookOAuthController extends Controller
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    /**
     * Step 1: Redirect to Facebook Login dialog.
     */
    public function redirect(): RedirectResponse
    {
        $state = Str::random(40);
        session(['facebook_oauth_state' => $state]);

        $params = http_build_query([
            'client_id' => config('services.facebook.client_id'),
            'redirect_uri' => route('facebook.callback'),
            'scope' => 'pages_show_list,pages_manage_posts,instagram_basic,instagram_content_publish',
            'response_type' => 'code',
            'state' => $state,
        ]);

        return redirect("https://www.facebook.com/v21.0/dialog/oauth?{$params}");
    }

    /**
     * Step 2: Handle the callback from Facebook.
     * Exchange code → short-lived → long-lived → get Pages + IG accounts.
     */
    public function callback(Request $request): RedirectResponse
    {
        // Verify state
        if ($request->input('state') !== session('facebook_oauth_state')) {
            return redirect()->route('accounts.index')
                ->with('error', 'État OAuth invalide. Veuillez réessayer.');
        }

        session()->forget('facebook_oauth_state');

        // Check for errors from Facebook
        if ($request->has('error')) {
            return redirect()->route('accounts.index')
                ->with('error', 'Autorisation Facebook refusée : ' . $request->input('error_description', 'Erreur inconnue'));
        }

        $code = $request->input('code');
        if (! $code) {
            return redirect()->route('accounts.index')
                ->with('error', 'Code d\'autorisation manquant.');
        }

        try {
            // Exchange code for short-lived token
            $shortLived = $this->exchangeCodeForToken($code);
            if (! $shortLived) {
                return redirect()->route('accounts.index')
                    ->with('error', 'Impossible d\'obtenir le token Facebook.');
            }

            // Exchange for long-lived token
            $longLived = $this->exchangeForLongLivedToken($shortLived);
            if (! $longLived) {
                return redirect()->route('accounts.index')
                    ->with('error', 'Impossible d\'obtenir un token longue durée.');
            }

            // Try /me/accounts first
            $pages = $this->fetchPages($longLived);

            // Fallback: if /me/accounts returns empty, use debug_token
            // to get page IDs from granular_scopes, then query each directly
            if (empty($pages)) {
                Log::info('Facebook /me/accounts returned empty, trying granular_scopes fallback');
                $pages = $this->fetchPagesViaDebugToken($longLived);
            }

            if (empty($pages)) {
                return redirect()->route('accounts.index')
                    ->with('error', 'Aucune Page Facebook trouvée. Assurez-vous d\'être administrateur d\'au moins une Page.');
            }

            // For each page, fetch profile picture and Instagram Business Account
            foreach ($pages as &$page) {
                $page['picture_url'] = $this->fetchPagePicture($page['id'], $page['access_token']);
                $page['instagram'] = $this->fetchInstagramAccount($page['id'], $page['access_token']);
            }

            session(['facebook_oauth_pages' => $pages]);

            return redirect()->route('facebook.select');
        } catch (\Throwable $e) {
            Log::error('Facebook OAuth error', ['message' => $e->getMessage()]);

            return redirect()->route('accounts.index')
                ->with('error', 'Erreur lors de la connexion Facebook : ' . $e->getMessage());
        }
    }

    /**
     * Step 3: Display the page/account selection form.
     */
    public function select(): View|RedirectResponse
    {
        $pages = session('facebook_oauth_pages');

        if (empty($pages)) {
            return redirect()->route('accounts.index')
                ->with('error', 'Aucune donnée Facebook en session. Veuillez recommencer.');
        }

        return view('accounts.facebook-select', compact('pages'));
    }

    /**
     * Step 4: Create/update SocialAccount records and link to current user.
     */
    public function connect(Request $request): RedirectResponse
    {
        $request->validate([
            'selected_pages' => 'nullable|array',
            'selected_pages.*' => 'string',
            'selected_instagram' => 'nullable|array',
            'selected_instagram.*' => 'string',
        ]);

        $pages = session('facebook_oauth_pages', []);
        $selectedPageIds = $request->input('selected_pages', []);
        $selectedIgIds = $request->input('selected_instagram', []);

        if (empty($selectedPageIds) && empty($selectedIgIds)) {
            return redirect()->route('accounts.index')
                ->with('error', 'Aucun compte sélectionné.');
        }

        $facebookPlatform = Platform::where('slug', 'facebook')->firstOrFail();
        $instagramPlatform = Platform::where('slug', 'instagram')->firstOrFail();
        $user = $request->user();
        $created = 0;
        $updated = 0;
        $linked = 0;

        foreach ($pages as $page) {
            // Facebook Page
            if (in_array($page['id'], $selectedPageIds)) {
                $account = SocialAccount::where('platform_id', $facebookPlatform->id)
                    ->where('platform_account_id', $page['id'])
                    ->first();

                if ($account) {
                    $account->update([
                        'name' => $page['name'],
                        'profile_picture_url' => $page['picture_url'] ?? $account->profile_picture_url,
                        'credentials' => [
                            'page_id' => $page['id'],
                            'access_token' => $page['access_token'],
                        ],
                    ]);
                    $updated++;
                } else {
                    $account = SocialAccount::create([
                        'platform_id' => $facebookPlatform->id,
                        'platform_account_id' => $page['id'],
                        'name' => $page['name'],
                        'profile_picture_url' => $page['picture_url'] ?? null,
                        'credentials' => [
                            'page_id' => $page['id'],
                            'access_token' => $page['access_token'],
                        ],
                        'language' => $user->default_language ?? 'fr',
                        'is_active' => true,
                    ]);
                    $created++;
                }

                // Link to current user (ignore if already linked)
                if (! $account->users()->where('user_id', $user->id)->exists()) {
                    $account->users()->attach($user->id);
                    $linked++;
                }
            }

            // Instagram Business Account
            if (! empty($page['instagram']) && in_array($page['instagram']['id'], $selectedIgIds)) {
                $igName = $page['instagram']['username']
                    ?? $page['instagram']['name']
                    ?? $page['name'] . ' (Instagram)';
                $igPicture = $page['instagram']['profile_picture_url'] ?? null;

                $account = SocialAccount::where('platform_id', $instagramPlatform->id)
                    ->where('platform_account_id', $page['instagram']['id'])
                    ->first();

                if ($account) {
                    $account->update([
                        'name' => $igName,
                        'profile_picture_url' => $igPicture ?? $account->profile_picture_url,
                        'credentials' => [
                            'account_id' => $page['instagram']['id'],
                            'access_token' => $page['access_token'],
                        ],
                    ]);
                    $updated++;
                } else {
                    $account = SocialAccount::create([
                        'platform_id' => $instagramPlatform->id,
                        'platform_account_id' => $page['instagram']['id'],
                        'name' => $igName,
                        'profile_picture_url' => $igPicture,
                        'credentials' => [
                            'account_id' => $page['instagram']['id'],
                            'access_token' => $page['access_token'],
                        ],
                        'language' => $user->default_language ?? 'fr',
                        'is_active' => true,
                    ]);
                    $created++;
                }

                if (! $account->users()->where('user_id', $user->id)->exists()) {
                    $account->users()->attach($user->id);
                    $linked++;
                }
            }
        }

        session()->forget(['facebook_oauth_state', 'facebook_oauth_pages']);

        $parts = [];
        if ($created > 0) {
            $parts[] = "{$created} compte(s) créé(s)";
        }
        if ($updated > 0) {
            $parts[] = "{$updated} compte(s) mis à jour";
        }
        if ($linked > 0) {
            $parts[] = "{$linked} compte(s) lié(s) à votre profil";
        }

        return redirect()->route('platforms.facebook')
            ->with('success', implode(', ', $parts) ?: 'Aucun changement effectué.');
    }

    // ─── Graph API helpers ──────────────────────────────────────────

    private function exchangeCodeForToken(string $code): ?string
    {
        $response = Http::get(self::API_BASE . '/oauth/access_token', [
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'redirect_uri' => route('facebook.callback'),
            'code' => $code,
        ]);

        if ($response->failed()) {
            Log::error('Facebook token exchange failed', ['body' => $response->body()]);
            return null;
        }

        return $response->json('access_token');
    }

    private function exchangeForLongLivedToken(string $shortLivedToken): ?string
    {
        $response = Http::get(self::API_BASE . '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if ($response->failed()) {
            Log::error('Facebook long-lived token exchange failed', ['body' => $response->body()]);
            return null;
        }

        return $response->json('access_token');
    }

    private function fetchPages(string $userToken): array
    {
        $pages = [];
        $url = self::API_BASE . '/me/accounts';
        $params = [
            'fields' => 'id,name,category,access_token',
            'access_token' => $userToken,
            'limit' => 100,
        ];

        do {
            $response = Http::get($url, $params);

            if ($response->failed()) {
                Log::error('Facebook fetch pages failed', ['body' => $response->body()]);
                break;
            }

            $data = $response->json();
            foreach ($data['data'] ?? [] as $page) {
                $pages[] = $page;
            }

            $url = $data['paging']['next'] ?? null;
            $params = [];
        } while ($url);

        return $pages;
    }

    /**
     * Fallback: use debug_token to extract page IDs from granular_scopes,
     * then query each page directly to get its name and access_token.
     */
    private function fetchPagesViaDebugToken(string $userToken): array
    {
        $appToken = config('services.facebook.client_id') . '|' . config('services.facebook.client_secret');

        $debugResponse = Http::get(self::API_BASE . '/debug_token', [
            'input_token' => $userToken,
            'access_token' => $appToken,
        ]);

        if ($debugResponse->failed()) {
            Log::error('Facebook debug_token failed', ['body' => $debugResponse->body()]);
            return [];
        }

        $debugData = $debugResponse->json('data', []);
        $granularScopes = $debugData['granular_scopes'] ?? [];

        // Extract page IDs from pages_show_list scope
        $pageIds = [];
        foreach ($granularScopes as $scope) {
            if ($scope['scope'] === 'pages_show_list') {
                $pageIds = $scope['target_ids'] ?? [];
                break;
            }
        }

        if (empty($pageIds)) {
            // Try pages_manage_posts as fallback
            foreach ($granularScopes as $scope) {
                if ($scope['scope'] === 'pages_manage_posts') {
                    $pageIds = $scope['target_ids'] ?? [];
                    break;
                }
            }
        }

        if (empty($pageIds)) {
            Log::warning('Facebook granular_scopes has no page IDs', ['scopes' => $granularScopes]);
            return [];
        }

        Log::info('Facebook fetching pages via direct query', ['page_ids' => $pageIds]);

        $pages = [];
        foreach ($pageIds as $pageId) {
            $response = Http::get(self::API_BASE . "/{$pageId}", [
                'fields' => 'id,name,category,access_token',
                'access_token' => $userToken,
            ]);

            if ($response->successful() && $response->json('id')) {
                $pages[] = $response->json();
            } else {
                Log::warning('Facebook direct page query failed', [
                    'page_id' => $pageId,
                    'body' => $response->body(),
                ]);
            }
        }

        return $pages;
    }

    private function fetchPagePicture(string $pageId, string $pageToken): ?string
    {
        $response = Http::get(self::API_BASE . "/{$pageId}/picture", [
            'redirect' => 'false',
            'type' => 'large',
            'access_token' => $pageToken,
        ]);

        if ($response->successful()) {
            return $response->json('data.url');
        }

        return null;
    }

    private function fetchInstagramAccount(string $pageId, string $pageToken): ?array
    {
        $response = Http::get(self::API_BASE . "/{$pageId}", [
            'fields' => 'instagram_business_account{id,name,username,profile_picture_url}',
            'access_token' => $pageToken,
        ]);

        if ($response->failed()) {
            return null;
        }

        return $response->json('instagram_business_account');
    }
}
