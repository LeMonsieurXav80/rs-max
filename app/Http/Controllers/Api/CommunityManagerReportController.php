<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountActionEvent;
use App\Services\Agent\AccountActionBudget;
use App\Services\Agent\AutomationJournal;
use App\Services\TelegramNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommunityManagerReportController extends Controller
{
    public function report(Request $r)
    {
        abort_unless($r->user()->isManager(), 403);
        $v = $r->validate(['hours' => 'sometimes|integer|min:1|max:48', 'note' => 'nullable|string|max:600',
            'dry_run' => 'sometimes|boolean', 'report_key' => 'nullable|uuid', 'agent_cost' => 'nullable|string|max:400']);
        $hours = $v['hours'] ?? 24;
        $since = now()->subHours($hours);
        $accounts = $r->user()->connectedSocialAccounts()->with('platform')->get();
        $rows = $accounts->map(function ($a) use ($since) {
            $events = AccountActionEvent::where('social_account_id', $a->id)->where('created_at', '>=', $since)->latest('id')->get();
            $lastIssue = $events->whereIn('status', ['failed', 'cancelled', 'uncertain'])->first();

            return ['account_id' => $a->id, 'name' => $a->name, 'platform' => $a->platform->name,
                'session' => $a->browser_session_status, 'attempted' => $events->count(),
                'confirmed' => $events->where('status', 'confirmed')->count(), 'completed' => $events->where('status', 'completed')->count(),
                'unchanged' => $events->where('status', 'unchanged')->count(), 'failed' => $events->whereIn('status', ['failed', 'cancelled'])->count(),
                'uncertain' => $events->where('status', 'uncertain')->count(), 'in_progress' => $events->where('status', 'attempted')->count(),
                'last_issue' => app(AutomationJournal::class)->text($lastIssue?->error, 150),
                'cadence' => app(AccountActionBudget::class)->usage($a)];
        })->values();
        $text = "RS-Max · bilan des {$hours} dernières heures";
        $active = $rows->filter(fn ($row) => $row['attempted'] > 0 || $row['session'] === 'expired');
        if ($active->isEmpty()) {
            $text .= "\nAucune activité sur la période.";
        }
        foreach ($active as $row) {
            $text .= "\n\n{$row['name']} · {$row['platform']} — Confirmées {$row['confirmed']} · Échecs {$row['failed']} · Incertaines {$row['uncertain']}";
            if ($row['completed'] || $row['in_progress']) {
                $text .= " {$row['completed']} sans preuve, {$row['in_progress']} en cours.";
            }
            if ($row['last_issue']) {
                $text .= "\nDernier incident · {$row['last_issue']}";
            }
            if ($row['session'] === 'expired') {
                $text .= "\nSession expirée. Renvoyer la session avec Companion.";
            }
        }
        if (! empty($v['note'])) {
            $text .= "\n\nConstat · ".app(AutomationJournal::class)->text($v['note'], 300);
        }
        if (! empty($v['agent_cost'])) {
            // Mesuré par Hermès (cron/usage_audit.jsonl) : RS-Max ne voit pas les jetons du modèle.
            $text .= "\n\nCoût agent · ".app(AutomationJournal::class)->text($v['agent_cost'], 400);
        }
        $data = ['hours' => $hours, 'accounts' => $rows, 'text' => $text];
        if ($r->isMethod('get') || $r->boolean('dry_run', true)) {
            return response()->json(['dry_run' => true] + $data);
        }
        abort_unless(isset($v['report_key']), 422, 'Une clé UUID de rapport est requise.');
        $key = 'cm-report:'.$r->user()->id.':'.$v['report_key'];
        if (! Cache::add($key, ['status' => 'requested', 'sent' => false], now()->addDays(2))) {
            return response()->json(['already_requested' => true, 'delivery' => Cache::get($key)] + $data);
        }
        $delivery = TelegramNotificationService::sendConfiguredText(mb_substr($text, 0, 3900));
        Cache::put($key, $delivery, now()->addDays(2));

        return response()->json(['dry_run' => false, 'delivery' => $delivery] + $data);
    }
}
