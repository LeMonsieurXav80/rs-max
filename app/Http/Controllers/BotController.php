<?php

namespace App\Http\Controllers;

use App\Models\BotActionLog;
use App\Models\BotSearchTerm;
use App\Models\SocialAccount;
use App\Services\Bot\BlueskyBotService;
use App\Services\Bot\FacebookBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BotController extends Controller
{
    public function index(Request $request): View
    {
        // Bluesky accounts with their search terms
        $blueskyAccounts = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'bluesky'))
            ->with('platform')
            ->get();

        $facebookAccounts = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'facebook'))
            ->with('platform')
            ->get();

        // Search terms grouped by account
        $searchTerms = BotSearchTerm::with('socialAccount.platform')
            ->orderBy('social_account_id')
            ->orderBy('term')
            ->get();

        // Recent action logs
        $logs = BotActionLog::with('socialAccount.platform')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Stats summary
        $todayStats = BotActionLog::where('success', true)
            ->whereDate('created_at', today())
            ->selectRaw('action_type, count(*) as total')
            ->groupBy('action_type')
            ->pluck('total', 'action_type');

        return view('bot.index', compact(
            'blueskyAccounts',
            'facebookAccounts',
            'searchTerms',
            'logs',
            'todayStats',
        ));
    }

    public function addTerm(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'social_account_id' => 'required|exists:social_accounts,id',
            'term' => 'required|string|max:100',
            'max_likes_per_run' => 'nullable|integer|min:1|max:50',
            'like_replies' => 'nullable|boolean',
        ]);

        BotSearchTerm::updateOrCreate(
            [
                'social_account_id' => $validated['social_account_id'],
                'term' => mb_strtolower(trim($validated['term'])),
            ],
            [
                'is_active' => true,
                'max_likes_per_run' => $validated['max_likes_per_run'] ?? 10,
                'like_replies' => $validated['like_replies'] ?? true,
            ]
        );

        return back()->with('success', 'Terme de recherche ajouté.');
    }

    public function removeTerm(BotSearchTerm $term): RedirectResponse
    {
        $term->delete();

        return back()->with('success', 'Terme supprimé.');
    }

    public function toggleTerm(BotSearchTerm $term): JsonResponse
    {
        $term->update(['is_active' => ! $term->is_active]);

        return response()->json(['is_active' => $term->is_active]);
    }

    public function runBluesky(Request $request): RedirectResponse
    {
        $accountId = $request->input('social_account_id');
        $account = SocialAccount::findOrFail($accountId);

        try {
            $service = new BlueskyBotService;
            $result = $service->runForAccount($account);

            $likebackInfo = isset($result['likeback_likes']) ? " (dont {$result['likeback_likes']} like-backs)" : '';
            $message = "Bluesky bot : {$result['total_likes']} likes effectués{$likebackInfo}.";
            if (isset($result['error'])) {
                $message .= " Erreur : {$result['error']}";
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('BlueskyBot: exception', ['error' => $e->getMessage()]);
            $message = "Bluesky bot : erreur - {$e->getMessage()}";
        }

        return back()->with('success', $message);
    }

    public function runFacebook(Request $request): RedirectResponse
    {
        $accountId = $request->input('social_account_id');
        $account = SocialAccount::findOrFail($accountId);

        try {
            $service = new FacebookBotService;
            $result = $service->runForAccount($account);

            $message = "Facebook bot : {$result['total_likes']} commentaires likés.";
            if (isset($result['error'])) {
                $message .= " Erreur : {$result['error']}";
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('FacebookBot: exception', ['error' => $e->getMessage()]);
            $message = "Facebook bot : erreur - {$e->getMessage()}";
        }

        return back()->with('success', $message);
    }

    public function clearLogs(): RedirectResponse
    {
        BotActionLog::truncate();

        return back()->with('success', 'Historique des actions vidé.');
    }
}
