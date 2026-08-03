<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotActionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Point d'entrée de l'extension Chrome « RS-Max Companion ».
 *
 * L'extension agit dans le navigateur de l'utilisateur (là où les API
 * officielles ne permettent rien) et remonte ici ce qu'elle a fait, pour que
 * ces actions comptent dans les statistiques au même titre que celles du bot.
 */
class ExtensionApiController extends Controller
{
    /**
     * POST /api/extension/actions — Remontée en lot des actions effectuées.
     *
     * Idempotence : aucune. L'extension ne rejoue un lot que s'il a échoué,
     * et un doublon d'action reste préférable à une action perdue.
     */
    public function actions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'actions' => 'required|array|min:1|max:200',
            'actions.*.social_account_id' => 'required|integer|exists:social_accounts,id',
            'actions.*.action_type' => 'required|string|max:100',
            'actions.*.target_uri' => 'required|string|max:2000',
            'actions.*.target_author' => 'nullable|string|max:255',
            'actions.*.target_text' => 'nullable|string|max:2000',
            'actions.*.success' => 'nullable|boolean',
            'actions.*.error' => 'nullable|string|max:1000',
            'actions.*.metadata' => 'nullable|array',
            'actions.*.performed_at' => 'nullable|date',
        ]);

        // On ne fait confiance à aucun social_account_id venu du navigateur :
        // seuls les comptes réellement rattachés à l'utilisateur sont acceptés.
        $allowed = $request->user()->activeSocialAccounts()->pluck('social_accounts.id')->all();

        $rows = [];
        $rejected = 0;
        $now = now();

        foreach ($validated['actions'] as $action) {
            if (! in_array($action['social_account_id'], $allowed, true)) {
                $rejected++;

                continue;
            }

            $rows[] = [
                'social_account_id' => $action['social_account_id'],
                'action_type' => $action['action_type'],
                'source' => 'extension',
                'target_uri' => $action['target_uri'],
                'target_author' => $action['target_author'] ?? null,
                'target_text' => $action['target_text'] ?? null,
                'search_term' => null,
                'success' => $action['success'] ?? true,
                'error' => $action['error'] ?? null,
                'metadata' => isset($action['metadata']) ? json_encode($action['metadata']) : null,
                'performed_at' => isset($action['performed_at']) ? \Carbon\Carbon::parse($action['performed_at']) : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            BotActionLog::insert($rows);
        }

        return response()->json([
            'stored' => count($rows),
            'rejected' => $rejected,
        ], 201);
    }

    /**
     * GET /api/extension/summary — Compte rendu des actions de l'extension.
     *
     * Sert au panneau (et à toi) pour vérifier d'un coup d'œil ce qui est
     * réellement passé, échecs compris.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
            'account_id' => 'nullable|integer',
        ]);

        $days = $validated['days'] ?? 30;
        $allowed = $request->user()->activeSocialAccounts()->pluck('social_accounts.id')->all();

        $accountIds = isset($validated['account_id'])
            ? array_values(array_intersect($allowed, [$validated['account_id']]))
            : $allowed;

        $rows = BotActionLog::query()
            ->where('source', 'extension')
            ->whereIn('social_account_id', $accountIds)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('action_type, success, COUNT(*) as total')
            ->groupBy('action_type', 'success')
            ->get();

        $byAction = [];
        foreach ($rows as $row) {
            $key = $row->action_type;
            $byAction[$key] ??= ['action_type' => $key, 'success' => 0, 'failed' => 0];
            $byAction[$key][$row->success ? 'success' : 'failed'] += (int) $row->total;
        }

        return response()->json([
            'days' => $days,
            'actions' => array_values($byAction),
            'total' => $rows->sum('total'),
        ]);
    }
}
