<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountActionEvent;
use App\Services\Agent\AccountActionBudget;
use App\Services\TelegramNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommunityManagerReportController extends Controller
{
    public function report(Request $r)
    {
        abort_unless($r->user()->isManager(), 403);
        $v = $r->validate(['hours' => 'sometimes|integer|min:1|max:48', 'note' => 'nullable|string|max:600',
            'dry_run' => 'sometimes|boolean', 'report_key' => 'nullable|uuid']);
        $hours = $v['hours'] ?? 24;
        $accounts = $r->user()->connectedSocialAccounts()->with('platform')->get();
        $rows = $accounts->map(function ($a) use ($hours) {
            $events = AccountActionEvent::where('social_account_id', $a->id)->where('created_at', '>=', now()->subHours($hours))->get();

            return ['account_id' => $a->id, 'name' => $a->name, 'platform' => $a->platform->name,
                'session' => $a->browser_session_status, 'attempted' => $events->count(),
                'confirmed' => $events->where('status', 'confirmed')->count(), 'completed' => $events->where('status', 'completed')->count(),
                'unchanged' => $events->where('status', 'unchanged')->count(), 'failed' => $events->whereIn('status', ['failed', 'cancelled'])->count(),
                'uncertain' => $events->where('status', 'uncertain')->count(), 'in_progress' => $events->where('status', 'attempted')->count(),
                'cadence' => app(AccountActionBudget::class)->usage($a)];
        })->values();
        $text = 'RS-Max · bilan Community Manager ('.$hours.' h)';
        foreach ($rows as $row) {
            $text .= "\n\n{$row['name']} · {$row['platform']}\n{$row['attempted']} tentative(s), {$row['confirmed']} confirmée(s), {$row['completed']} terminée(s) sans preuve, {$row['unchanged']} déjà présente(s)."
                ."\n{$row['failed']} échec(s), {$row['uncertain']} incertaine(s), {$row['in_progress']} en cours."
                ."\nDernière heure : {$row['cadence']['attempted']}/".($row['cadence']['limit'] ?? 'sans plafond').'.';
            if ($row['session'] === 'expired') {
                $text .= "\nSession expirée : renvoyer la session avec Companion.";
            }
        }
        if (! empty($v['note'])) {
            $text .= "\n\nAmélioration / constat : ".$v['note'];
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
