<?php

namespace App\Http\Controllers;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\ProfilePictureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LinkedInOAuthController extends Controller
{
    private const API_BASE = 'https://api.linkedin.com';
    private const API_VERSION = '202402';

    /**
     * Step 1: Redirect to LinkedIn OAuth authorization.
     */
    public function redirect(): RedirectResponse
    {
        $state = Str::random(40);
        session(['linkedin_oauth_state' => $state]);

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.linkedin.client_id'),
            'redirect_uri' => config('services.linkedin.redirect'),
            'scope' => 'openid profile w_member_social r_organization_admin w_organization_social rw_organization_admin',
            'state' => $state,
        ]);

        return redirect("https://www.linkedin.com/oauth/v2/authorization?{$params}");
    }

    /**
     * Step 2: Handle the callback from LinkedIn.
     * Exchange code → access_token → fetch profile → show selection page.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->input('state') !== session('linkedin_oauth_state')) {
            return redirect()->route('platforms.linkedin')
                ->with('error', 'État OAuth invalide. Veuillez réessayer.');
        }

        session()->forget('linkedin_oauth_state');

        if ($request->has('error')) {
            return redirect()->route('platforms.linkedin')
                ->with('error', 'Autorisation LinkedIn refusée : ' . $request->input('error_description', 'Erreur inconnue'));
        }

        $code = $request->input('code');
        if (! $code) {
            return redirect()->route('platforms.linkedin')
                ->with('error', 'Code d\'autorisation manquant.');
        }

        try {
            // Exchange code for tokens
            $tokenData = $this->exchangeCodeForToken($code);
            if (! $tokenData) {
                return redirect()->route('platforms.linkedin')
                    ->with('error', 'Impossible d\'obtenir le token LinkedIn.');
            }

            $accessToken = $tokenData['access_token'];
            $refreshToken = $tokenData['refresh_token'] ?? null;

            // Fetch user profile (userinfo endpoint)
            $profile = $this->fetchProfile($accessToken);
            if (empty($profile['sub'])) {
                return redirect()->route('platforms.linkedin')
                    ->with('error', 'Impossible de récupérer le profil LinkedIn.');
            }

            $personId = $profile['sub'];
            $displayName = trim(($profile['given_name'] ?? '') . ' ' . ($profile['family_name'] ?? '')) ?: 'LinkedIn User';
            $remoteProfilePic = $profile['picture'] ?? null;

            // Fetch organization pages the user administers
            $organizations = $this->fetchOrganizations($accessToken);

            // Store data in session for selection page
            session([
                'linkedin_oauth_data' => [
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'person_id' => $personId,
                    'display_name' => $displayName,
                    'profile_picture' => $remoteProfilePic,
                    'organizations' => $organizations,
                ],
            ]);

            return redirect()->route('linkedin.select');
        } catch (\Throwable $e) {
            Log::error('LinkedIn OAuth error', ['message' => $e->getMessage()]);

            return redirect()->route('platforms.linkedin')
                ->with('error', 'Erreur lors de la connexion LinkedIn : ' . $e->getMessage());
        }
    }

    /**
     * Step 3: Show selection page — personal profile + organization pages.
     */
    public function select(Request $request)
    {
        $data = session('linkedin_oauth_data');
        if (! $data) {
            return redirect()->route('platforms.linkedin')
                ->with('error', 'Session OAuth expirée. Veuillez reconnecter.');
        }

        return view('platforms.linkedin-select', [
            'profile' => [
                'id' => $data['person_id'],
                'name' => $data['display_name'],
                'picture' => $data['profile_picture'],
            ],
            'organizations' => $data['organizations'],
        ]);
    }

    /**
     * Step 4: Connect the selected accounts (personal profile and/or org pages).
     */
    public function connect(Request $request): RedirectResponse
    {
        $data = session('linkedin_oauth_data');
        if (! $data) {
            return redirect()->route('platforms.linkedin')
                ->with('error', 'Session OAuth expirée. Veuillez reconnecter.');
        }

        $selected = $request->input('accounts', []);
        if (empty($selected)) {
            return redirect()->route('linkedin.select')
                ->with('error', 'Veuillez sélectionner au moins un compte.');
        }

        $linkedinPlatform = Platform::where('slug', 'linkedin')->firstOrFail();
        $user = $request->user();
        $connected = [];

        foreach ($selected as $accountId) {
            $isPersonal = $accountId === $data['person_id'];

            if ($isPersonal) {
                $urn = "urn:li:person:{$data['person_id']}";
                $name = $data['display_name'];
                $remoteProfilePic = $data['profile_picture'];
                $accountType = 'person';
            } else {
                // Find org in the list
                $org = collect($data['organizations'])->firstWhere('id', $accountId);
                if (! $org) {
                    continue;
                }
                $urn = "urn:li:organization:{$org['id']}";
                $name = $org['name'];
                $remoteProfilePic = $org['logo'] ?? null;
                $accountType = 'organization';
            }

            // Download profile picture
            $localPic = $remoteProfilePic
                ? ProfilePictureService::download($remoteProfilePic, 'linkedin', $accountId)
                : null;

            $account = SocialAccount::where('platform_id', $linkedinPlatform->id)
                ->where('platform_account_id', $accountId)
                ->first();

            $credentials = [
                'person_urn' => $urn,
                'account_type' => $accountType,
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at' => now()->addDays(60)->toIso8601String(),
            ];

            if ($account) {
                $account->update([
                    'name' => $name,
                    'profile_picture_url' => $localPic ?? $account->profile_picture_url,
                    'credentials' => $credentials,
                ]);
            } else {
                $account = SocialAccount::create([
                    'platform_id' => $linkedinPlatform->id,
                    'platform_account_id' => $accountId,
                    'name' => $name,
                    'profile_picture_url' => $localPic,
                    'credentials' => $credentials,
                    'languages' => [$user->default_language ?? 'fr'],
                ]);
            }

            if (! $account->users()->where('user_id', $user->id)->exists()) {
                $account->users()->attach($user->id, ['is_active' => true]);
            }

            $connected[] = $name;
        }

        session()->forget('linkedin_oauth_data');

        $names = implode(', ', $connected);

        return redirect()->route('platforms.linkedin')
            ->with('success', "Compte(s) LinkedIn connecté(s) : {$names}");
    }

    // ─── API helpers ──────────────────────────────────────────

    private function exchangeCodeForToken(string $code): ?array
    {
        $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
            'redirect_uri' => config('services.linkedin.redirect'),
        ]);

        if ($response->failed()) {
            Log::error('LinkedIn token exchange failed', ['body' => $response->body()]);
            return null;
        }

        $data = $response->json();
        if (empty($data['access_token'])) {
            Log::error('LinkedIn token exchange: missing access_token', ['data' => $data]);
            return null;
        }

        return $data;
    }

    /**
     * Fetch the authenticated user's profile via OpenID Connect userinfo.
     */
    private function fetchProfile(string $accessToken): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
        ])->get('https://api.linkedin.com/v2/userinfo');

        if ($response->failed()) {
            Log::error('LinkedIn fetch profile failed', ['body' => $response->body()]);
            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * Fetch organizations where the user is an admin.
     */
    private function fetchOrganizations(string $accessToken): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'LinkedIn-Version' => self::API_VERSION,
            'X-Restli-Protocol-Version' => '2.0.0',
        ])->get(self::API_BASE . '/v2/organizationAcls', [
            'q' => 'roleAssignee',
            'role' => 'ADMINISTRATOR',
            'projection' => '(elements*(organization~(id,localizedName,logoV2(cropped~:playableStreams))))',
        ]);

        if ($response->failed()) {
            Log::warning('LinkedIn fetch organizations failed', ['body' => $response->body()]);
            return [];
        }

        $elements = $response->json('elements') ?? [];
        $organizations = [];

        foreach ($elements as $element) {
            $org = $element['organization~'] ?? [];
            $orgUrn = $element['organization'] ?? '';

            // Extract org ID from URN (urn:li:organization:12345)
            $orgId = '';
            if (preg_match('/urn:li:organization:(\d+)/', $orgUrn, $matches)) {
                $orgId = $matches[1];
            }

            if (! $orgId) {
                continue;
            }

            // Extract logo URL from logoV2
            $logoUrl = null;
            $cropped = $org['logoV2']['cropped~']['elements'] ?? [];
            if (! empty($cropped)) {
                // Get the largest available logo
                $sorted = collect($cropped)->sortByDesc(fn ($el) => $el['data']['com.linkedin.digitalmedia.mediaartifact.StillImage']['displaySize']['width'] ?? 0);
                $logoUrl = $sorted->first()['identifiers'][0]['identifier'] ?? null;
            }

            $organizations[] = [
                'id' => $orgId,
                'name' => $org['localizedName'] ?? "Organization {$orgId}",
                'logo' => $logoUrl,
            ];
        }

        return $organizations;
    }

    /**
     * Refresh an expired access token using the refresh token.
     */
    public static function refreshAccessToken(SocialAccount $account): ?string
    {
        $credentials = $account->credentials;
        $refreshToken = $credentials['refresh_token'] ?? null;

        if (! $refreshToken) {
            return null;
        }

        $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ]);

        if ($response->failed()) {
            Log::error('LinkedIn refresh token failed', ['body' => $response->body()]);
            return null;
        }

        $data = $response->json();
        $newAccessToken = $data['access_token'] ?? null;

        if ($newAccessToken) {
            $account->update([
                'credentials' => array_merge($credentials, [
                    'access_token' => $newAccessToken,
                    'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                    'token_expires_at' => now()->addDays(60)->toIso8601String(),
                ]),
            ]);
        }

        return $newAccessToken;
    }
}
