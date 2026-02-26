<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\FollowersService;
use App\Services\Import\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImportController extends Controller
{
    /**
     * Get import info (quota cost, cooldown status) for an account.
     */
    public function info(Request $request, int $accountId, ImportService $importService): JsonResponse
    {
        $user = $request->user();
        $account = SocialAccount::with('platform')->findOrFail($accountId);

        // Authorization check
        if (! $user->is_admin && ! $account->users()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $limit = (int) $request->input('limit', 50);

        // Check if import is allowed
        $canImport = $importService->canImport($account);

        // Get quota cost
        $quotaCost = $importService->getQuotaCost($account, $limit);

        return response()->json([
            'can_import' => $canImport['allowed'],
            'cooldown_reason' => $canImport['reason'],
            'quota_cost' => $quotaCost['cost'],
            'quota_description' => $quotaCost['description'],
            'last_import' => $account->last_history_import_at?->diffForHumans(),
        ]);
    }

    /**
     * Import historical posts for an account.
     */
    public function import(Request $request, int $accountId, ImportService $importService): JsonResponse
    {
        $user = $request->user();
        $account = SocialAccount::with('platform')->findOrFail($accountId);

        // Authorization check
        if (! $user->is_admin && ! $account->users()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $limit = (int) $request->input('limit', 50);
        $limit = min(max($limit, 1), 200); // Clamp between 1 and 200

        // Check if import is allowed
        $canImport = $importService->canImport($account);
        if (! $canImport['allowed']) {
            return response()->json([
                'success' => false,
                'error' => $canImport['reason'],
            ], 429); // Too Many Requests
        }

        // Perform import
        Log::info("Starting import for account {$accountId}", [
            'platform' => $account->platform->slug,
            'limit' => $limit,
            'user_id' => $user->id,
        ]);

        $result = $importService->import($account, $limit);

        // Also sync follower count
        $followersService = app(FollowersService::class);
        $followers = $followersService->syncFollowers($account);

        if ($result['success']) {
            Log::info("Import completed for account {$accountId}", [
                'imported' => $result['imported'],
                'followers' => $followers,
            ]);

            return response()->json([
                'success' => true,
                'imported' => $result['imported'],
                'followers' => $followers,
                'message' => "{$result['imported']} publication(s) importée(s) avec succès.",
            ]);
        } else {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 500);
        }
    }

    /**
     * Sync follower counts for all active accounts.
     */
    public function syncFollowers(Request $request, FollowersService $followersService): JsonResponse
    {
        if (! $request->user()->is_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $results = $followersService->syncAllActive();

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }
}
