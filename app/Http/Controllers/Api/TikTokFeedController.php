<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteTikTokEngagement;
use App\Models\BotActionLog;
use App\Models\SocialAccount;
use App\Models\TikTokEngagement;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\TikTokVideoCommentGenerator;
use App\Services\Browser\BrowserException;
use App\Services\Browser\BrowserTaskQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Lectures et préparations de l'agent ; l'envoi reste une validation explicite séparée. */
class TikTokFeedController extends Controller
{
    public function __construct(private AgentCatalog $catalog) {}

    private function account(Request $request, int $id): SocialAccount
    {
        abort_unless($request->user()->isManager(), 403);
        $account = $request->user()->connectedSocialAccounts()->with(['platform', 'persona'])->find($id);
        abort_unless($account, 403, 'Compte non autorisé.');
        abort_unless($account->platform->slug === 'tiktok' && $account->usesBrowser() && filled($account->browser_profile)
            && filled($account->browser_username) && $account->browser_session_status === 'connected', 422, 'Session TikTok NAS active requise.');

        return $account;
    }

    private function enabled(SocialAccount $account, string $action): array
    {
        $state = $this->catalog->forAccount($account)->get($action);
        abort_unless($state && $state['status'] === 'ready' && $state['enabled'], 403, 'Action désactivée sur ce compte.');
        abort_if($state['left'] < 1, 429, 'Quota du jour atteint.');

        return $state;
    }

    public function read(Request $request, int $id, BrowserTaskQueue $browser): JsonResponse
    {
        $account = $this->account($request, $id);
        $data = $request->validate(['action' => 'required|in:feed.read,video.comments',
            'url' => ['required_if:action,video.comments', 'nullable', 'string', 'regex:~^https://www\.tiktok\.com/@[a-zA-Z0-9_.]+/video/[0-9]+$~'],
            'limit' => 'sometimes|integer|min:1|max:10', 'feed' => 'sometimes|in:following,foryou']);
        $state = $this->enabled($account, $data['action']);
        $url = $data['action'] === 'feed.read' ? 'https://www.tiktok.com/'.(($data['feed'] ?? 'following') === 'following' ? 'following' : '') : $data['url'];
        try {
            $result = $browser->run($account, 'tiktok.'.$data['action'], ['username' => $account->browser_username,
                'url' => $url, 'limit' => $data['limit'] ?? 3], 100);
        } catch (BrowserException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        BotActionLog::create(['social_account_id' => $account->id, 'action_type' => $this->catalog->logType($data['action']),
            'source' => AgentCatalog::SOURCE, 'target_uri' => $url, 'success' => true, 'performed_at' => now()]);
        if ($data['action'] === 'video.comments') {
            abort_unless(isset($result['video'], $result['comments'], $result['complete']) && ($result['video']['url'] ?? null) === $url, 502, 'Lecture vidéo invalide.');
            $snapshotId = (string) Str::uuid();
            Cache::put('tiktok-video:'.$snapshotId, ['account_id' => $account->id] + $result, now()->addHour());
            $result['snapshot_id'] = $snapshotId;
        }

        return response()->json($result + ['left' => $state['left'] - 1]);
    }

    public function prepare(Request $request, int $id, TikTokVideoCommentGenerator $generator): JsonResponse
    {
        $account = $this->account($request, $id);
        $data = $request->validate(['action' => 'required|in:video.like,comment.like,video.comment',
            'snapshot_id' => 'required|uuid', 'comment_key' => 'required_if:action,comment.like|nullable|string|size:64']);
        $this->enabled($account, $data['action']);
        $snapshot = Cache::get('tiktok-video:'.$data['snapshot_id']);
        abort_unless($snapshot && $snapshot['account_id'] === $account->id, 422, 'Lecture expirée ou effectuée pour un autre compte. Relire la vidéo.');
        $url = $snapshot['video']['url'];
        $target = null;
        if ($data['action'] === 'comment.like') {
            $matches = collect($snapshot['comments'])->where('key', $data['comment_key']);
            abort_unless($matches->count() === 1, 422, 'Commentaire absent ou ambigu.');
            $target = $matches->first();
            abort_if(($target['ambiguous'] ?? false) || $target['author'] === $account->browser_username, 422, 'Commentaire ambigu ou du compte lui-même.');
        }
        $key = hash('sha256', $url.'|'.($target['key'] ?? 'video'));
        $externalDraft = $request->attributes->get('community_manager_draft');

        return Cache::lock('tiktok-prepare:'.$account->id.':'.$data['action'].':'.$key, 90)->block(2, function () use ($account, $data, $snapshot, $url, $target, $key, $generator, $externalDraft) {
            $existing = TikTokEngagement::where('social_account_id', $account->id)->where('action', $data['action'])->where('target_key', $key)->first();
            if ($existing && ! $externalDraft) {
                return response()->json($this->format($existing));
            }
            abort_if($existing && $existing->status !== 'prepared', 409, 'Action déjà tentée : aucun remplacement ni renvoi.');
            $payload = ['username' => $account->browser_username, 'url' => $url, 'target' => $target];
            $personaHash = null;
            if ($data['action'] === 'video.comment') {
                abort_unless($snapshot['complete'] === true || ($snapshot['accessible_complete'] ?? false) === true, 422, 'Lecture des commentaires accessibles incomplète : poursuivre la lecture avant de rédiger.');
                abort_unless($account->persona?->is_active && filled($account->persona->system_prompt), 422, 'Persona actif du compte obligatoire.');
                abort_if(collect($snapshot['comments'])->contains('author', $account->browser_username), 409, 'Ce compte a déjà commenté cette vidéo.');
                if ($externalDraft) {
                    abort_unless($externalDraft['persona_hash'] === TikTokEngagement::personaHash($account), 409, 'Persona modifié depuis la rédaction Hermès.');
                    $text = $externalDraft['text'];
                    $payload['draft_source'] = 'hermes_subscription';
                } else {
                    $text = $generator->generate($account, $snapshot);
                }
                abort_unless(filled($text), 503, 'Génération indisponible.');
                abort_if(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2 > 150, 422, 'Commentaire trop long : aucun texte tronqué.');
                $payload['text'] = $text;
                $payload['discussion'] = ['observed' => count($snapshot['comments']), 'expected' => $snapshot['expected'] ?? null,
                    'coverage' => $snapshot['complete'] ? 'reported_total' : 'accessible'];
                $personaHash = TikTokEngagement::personaHash($account);
            }
            $row = $existing ?? new TikTokEngagement;
            $row->fill(['social_account_id' => $account->id, 'action' => $data['action'],
                'target_key' => $key, 'payload' => $payload, 'persona_hash' => $personaHash])->save();

            return response()->json($this->format($row), 201);
        });
    }

    public function show(Request $request, TikTokEngagement $engagement): JsonResponse
    {
        $this->account($request, $engagement->social_account_id);

        return response()->json($this->format($engagement));
    }

    public function send(Request $request, TikTokEngagement $engagement): JsonResponse
    {
        $account = $this->account($request, $engagement->social_account_id);
        $this->enabled($account, $engagement->action);
        $request->validate(['dry_run' => 'sometimes|boolean']);
        abort_unless($engagement->status === 'prepared', 409, 'Action déjà réservée ou tentée. Vérifier son résultat, sans relancer.');
        abort_if($engagement->persona_hash && (! $account->persona?->is_active || $engagement->persona_hash !== TikTokEngagement::personaHash($account)), 409, 'Persona modifié depuis la préparation.');
        if ($request->boolean('dry_run', true)) {
            return response()->json(['dry_run' => true] + $this->format($engagement));
        }
        abort_unless(TikTokEngagement::whereKey($engagement->id)->where('status', 'prepared')->update(['status' => 'queued']), 409, 'Action déjà réservée.');
        ExecuteTikTokEngagement::dispatch($engagement->id);

        return response()->json(['id' => $engagement->id, 'status' => 'queued', 'dry_run' => false], 202);
    }

    private function format(TikTokEngagement $row): array
    {
        return ['id' => $row->id, 'account_id' => $row->social_account_id, 'action' => $row->action, 'status' => $row->status,
            'url' => $row->payload['url'], 'text' => $row->payload['text'] ?? null,
            'discussion' => $row->payload['discussion'] ?? null,
            'draft_source' => $row->payload['draft_source'] ?? ($row->action === 'video.comment' ? 'rsmax_api' : null),
            'target' => $row->payload['target'] ?? null, 'result' => $row->result, 'error' => $row->error];
    }
}
