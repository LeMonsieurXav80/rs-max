<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotActionLog;
use App\Services\Agent\AutomationJournal;
use Illuminate\Http\Request;

class AutomationLogController extends Controller
{
    public function query(Request $request, ?string $platform = null)
    {
        abort_unless($request->user()->isManager(), 403);
        $v = $request->validate(['account_id' => 'sometimes|nullable|integer|min:1', 'platform' => 'sometimes|nullable|string|max:40',
            'status' => 'sometimes|nullable|in:'.implode(',', AutomationJournal::STATUSES), 'action' => 'sometimes|nullable|string|max:100',
            'source' => 'sometimes|nullable|string|max:30', 'before_id' => 'sometimes|integer|min:1', 'limit' => 'sometimes|integer|min:1|max:100']);
        $accounts = $request->is('api/community-manager/*') ? $request->user()->connectedSocialAccounts() : $request->user()->visibleSocialAccounts();
        $ids = $accounts->pluck('social_accounts.id');
        if (! empty($v['account_id'])) {
            abort_unless($ids->contains($v['account_id']), 404);
        }
        $query = BotActionLog::with('socialAccount.platform')->whereIn('social_account_id', $ids)->where('created_at', '>=', now()->subDays(7));
        if ($platform ?? ($v['platform'] ?? null)) {
            $query->whereHas('socialAccount.platform', fn ($q) => $q->where('slug', $platform ?? $v['platform']));
        }
        foreach (['account_id' => 'social_account_id', 'action' => 'action_type', 'source' => 'source'] as $input => $column) {
            if (! empty($v[$input])) {
                $query->where($column, $v[$input]);
            }
        }
        if (! empty($v['status'])) {
            $query->where(function ($q) use ($v) {
                $q->where('metadata->status', $v['status']);
                if (in_array($v['status'], ['completed', 'failed'], true)) {
                    $q->orWhere(fn ($old) => $old->whereNull('metadata->status')->where('success', $v['status'] === 'completed'));
                }
            });
        }
        if (isset($v['before_id'])) {
            $query->where('id', '<', $v['before_id']);
        }

        return $query->orderByDesc('id');
    }

    public function index(Request $request, AutomationJournal $journal)
    {
        $limit = (int) $request->input('limit', 30);
        $query = $this->query($request);
        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json(['retention_days' => 7, 'logs' => $rows->map(fn ($log) => $journal->present($log)), 'has_more' => $more,
            'next_cursor' => $more ? $rows->last()?->id : null]);
    }
}
