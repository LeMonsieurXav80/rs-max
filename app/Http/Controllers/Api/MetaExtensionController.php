<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotActionLog;
use App\Models\SocialAccount;
use App\Services\Browser\MetaSessionService;
use Illuminate\Http\Request;

class MetaExtensionController extends Controller
{
    private function account(Request $request, string $platform, string $nativeId): SocialAccount
    {
        abort_unless(in_array($platform, MetaSessionService::PLATFORMS, true), 404);

        return $request->user()->activeSocialAccounts()->whereHas('platform', fn ($q) => $q->where('slug', $platform))
            ->where('platform_account_id', $nativeId)->firstOrFail();
    }

    public function status(Request $request, string $platform)
    {
        $data = $request->validate(['platform_account_id' => ['required', 'string', 'regex:/^\d{1,30}$/']]);
        $account = $this->account($request, $platform, $data['platform_account_id']);

        return response()->json(['account_id' => $account->id, 'driver' => $account->driver,
            'status' => $account->browser_session_status, 'username' => $account->browser_username]);
    }

    public function store(Request $request, string $platform, MetaSessionService $sessions)
    {
        abort_unless(in_array($platform, MetaSessionService::PLATFORMS, true), 404);
        $data = $request->validate([
            'platform_account_id' => ['required', 'string', 'regex:/^\d{1,30}$/'],
            'cookies' => 'required|array|min:2|max:100', 'cookies.*.name' => 'required|string|max:200',
            'cookies.*.value' => 'required|string|max:8000',
            'cookies.*.domain' => ['required', 'string', 'regex:/^\.?([a-z0-9-]+\.)*'.preg_quote($platform, '/').'\.com$/i'],
            'cookies.*.path' => 'nullable|string|max:500', 'cookies.*.expirationDate' => 'nullable|numeric',
            'cookies.*.hostOnly' => 'nullable|boolean', 'cookies.*.httpOnly' => 'nullable|boolean',
            'cookies.*.secure' => 'nullable|boolean', 'cookies.*.sameSite' => 'nullable|in:no_restriction,lax,strict,unspecified',
        ]);
        $account = $this->account($request, $platform, $data['platform_account_id']);
        $connected = $sessions->store($account, $data['cookies']);
        BotActionLog::create(['social_account_id' => $account->id, 'action_type' => $platform.'.session', 'source' => 'extension',
            'target_uri' => MetaSessionService::home($platform), 'success' => $connected, 'performed_at' => now()]);

        return response()->json(['status' => $account->browser_session_status, 'username' => $account->browser_username],
            $connected ? 200 : ($account->browser_session_status === 'pending' ? 202 : 409));
    }
}
