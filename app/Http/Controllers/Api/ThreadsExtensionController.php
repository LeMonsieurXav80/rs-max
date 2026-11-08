<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Threads\ThreadsSessionService;
use Illuminate\Http\Request;

class ThreadsExtensionController extends Controller
{
    private function account(Request $r, string $username)
    {
        abort_unless($r->user()->isManager(), 403);
        $accounts = $r->user()->connectedSocialAccounts()->whereHas('platform', fn ($q) => $q->where('slug', 'threads'))->get();
        $matches = $accounts->filter(fn ($a) => strtolower($a->browser_username ?? $a->name) === strtolower($username));
        abort_unless($matches->count() === 1, 422, 'Le profil Threads doit correspondre à un compte RS-Max attribué.');

        return $matches->first();
    }

    public function status(Request $r)
    {
        $r->validate(['username' => ['required', 'string', 'regex:/^[a-zA-Z0-9_.]{1,50}$/']]);
        $a = $this->account($r, $r->input('username'));

        return response()->json(['status' => $a->browser_session_status]);
    }

    public function store(Request $r, ThreadsSessionService $service)
    {
        $d = $r->validate(['username' => ['required', 'string', 'regex:/^[a-zA-Z0-9_.]{1,50}$/'], 'cookies' => 'required|array|min:2|max:100', 'cookies.*.name' => 'required|string|max:200', 'cookies.*.value' => 'required|string|max:8000', 'cookies.*.domain' => ['required', 'string', 'regex:/^\.?([a-z0-9-]+\.)*threads\.(com|net)$/'], 'cookies.*.path' => 'nullable|string|max:500', 'cookies.*.expirationDate' => 'nullable|numeric', 'cookies.*.hostOnly' => 'nullable|boolean', 'cookies.*.httpOnly' => 'nullable|boolean', 'cookies.*.secure' => 'nullable|boolean', 'cookies.*.sameSite' => 'nullable|string|max:20']);
        abort_unless(collect($d['cookies'])->contains(fn ($c) => $c['name'] === 'sessionid' && filled($c['value'])), 422, 'Session Threads absente.');
        $a = $this->account($r, $d['username']);
        $ok = $service->store($a, $d['username'], $d['cookies']);

        return response()->json(['status' => $a->fresh()->browser_session_status, 'username' => $a->browser_username], $ok ? 200 : 409);
    }
}
