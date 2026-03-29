<?php

namespace App\Http\Controllers;

use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Pinterest\PinterestApiService;
use App\Services\ProfilePictureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PinterestOAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        $state = Str::random(40);
        session(['pinterest_oauth_state' => $state]);

        $service = new PinterestApiService;
        $url = $service->getAuthorizationUrl($state);

        return redirect($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->input('state') !== session('pinterest_oauth_state')) {
            return redirect()->route('accounts.index')
                ->with('error', 'État OAuth invalide. Veuillez réessayer.');
        }

        session()->forget('pinterest_oauth_state');

        if ($request->has('error')) {
            return redirect()->route('accounts.index')
                ->with('error', 'Autorisation Pinterest refusée.');
        }

        $code = $request->input('code');
        if (! $code) {
            return redirect()->route('accounts.index')
                ->with('error', 'Code d\'autorisation manquant.');
        }

        $service = new PinterestApiService;

        $tokens = $service->exchangeCodeForTokens($code);
        if (! $tokens) {
            return redirect()->route('accounts.index')
                ->with('error', 'Impossible d\'obtenir les tokens Pinterest.');
        }

        $user = $service->getUserAccount($tokens['access_token']);
        if (! $user) {
            return redirect()->route('accounts.index')
                ->with('error', 'Impossible de récupérer le profil Pinterest.');
        }

        session([
            'pinterest_oauth_data' => [
                'user_id' => $user['username'],
                'username' => $user['username'],
                'display_name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username'],
                'profile_image' => $user['profile_image'] ?? null,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
            ],
        ]);

        return redirect()->route('pinterest.select');
    }

    public function select(): View|RedirectResponse
    {
        $data = session('pinterest_oauth_data');

        if (! $data) {
            return redirect()->route('accounts.index')
                ->with('error', 'Session Pinterest expirée. Veuillez réessayer.');
        }

        return view('platforms.pinterest-select', ['pinterestUser' => $data]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $data = session('pinterest_oauth_data');

        if (! $data) {
            return redirect()->route('accounts.index')
                ->with('error', 'Session Pinterest expirée. Veuillez réessayer.');
        }

        $platform = Platform::where('slug', 'pinterest')->first();
        if (! $platform) {
            return redirect()->route('accounts.index')
                ->with('error', 'Plateforme Pinterest non configurée.');
        }

        $user = $request->user();

        $existing = SocialAccount::where('platform_id', $platform->id)
            ->where('platform_account_id', $data['user_id'])
            ->first();

        $localPic = ! empty($data['profile_image'])
            ? ProfilePictureService::download($data['profile_image'], 'pinterest', $data['user_id'])
            : null;

        if ($existing) {
            $existing->update([
                'name' => $data['display_name'],
                'credentials' => [
                    'user_id' => $data['user_id'],
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'],
                ],
                'profile_picture_url' => $localPic ?? $existing->profile_picture_url,
            ]);

            if (! $existing->users()->where('user_id', $user->id)->exists()) {
                $existing->users()->attach($user->id);
            }

            session()->forget('pinterest_oauth_data');

            return redirect()->route('accounts.index')
                ->with('success', "Compte Pinterest '{$data['display_name']}' mis à jour !");
        }

        $account = SocialAccount::create([
            'platform_id' => $platform->id,
            'platform_account_id' => $data['user_id'],
            'name' => $data['display_name'],
            'credentials' => [
                'user_id' => $data['user_id'],
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
            ],
            'profile_picture_url' => $localPic,
        ]);

        $account->users()->attach($user->id, ['is_active' => true]);

        session()->forget('pinterest_oauth_data');

        return redirect()->route('accounts.index')
            ->with('success', "Compte Pinterest '{$data['display_name']}' connecté !");
    }
}
