<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareInboxDrafts;
use App\Models\BotActionLog;
use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\Agent\AgentCatalog;
use App\Services\Agent\AgentReader;
use App\Services\Browser\BrowserException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API de l'agent : réponses courtes, un appel pour tout savoir, sans HTML. */
class AgentApiController extends Controller
{
    public function __construct(private AgentCatalog $catalog) {}

    private function account(Request $request, int $id): SocialAccount
    {
        abort_unless($request->user()->isManager(), 403);
        $account = $request->user()->visibleSocialAccounts()->with('platform')->find($id);
        abort_unless($account, 404, 'Compte inconnu.');

        return $account;
    }

    /** Une seule requête suffit à l'agent pour savoir où il peut agir, et combien de fois. */
    public function accounts(Request $request): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $slugs = array_keys($this->catalog->platforms());
        $accounts = $request->user()->visibleSocialAccounts()->with('platform')
            ->whereHas('platform', fn ($q) => $q->whereIn('slug', $slugs))->orderBy('platform_id')->orderBy('name')->get();

        return response()->json(['accounts' => $accounts->map(fn (SocialAccount $a) => [
            'id' => $a->id, 'platform' => $a->platform->slug, 'name' => $a->name, 'session' => $a->browser_session_status,
            'actions' => $this->catalog->forAccount($a)->where('enabled', true)->where('status', 'ready')
                ->map(fn (array $action) => ['kind' => $action['kind'], 'left' => $action['left']]),
        ])->filter(fn (array $a) => $a['actions']->isNotEmpty())->values()]);
    }

    public function read(Request $request, int $id, string $action, AgentReader $reader): JsonResponse
    {
        $a = $this->account($request, $id);
        if ($a->platform->slug === 'threads') {
            $request->merge(['action' => $action]);

            return app(ThreadsAgentController::class)->read($request, $id, app(\App\Services\Threads\ThreadsAgent::class));
        }
        if (in_array($action, ['feed.read', 'video.comments'], true)) {
            $request->merge(['action' => $action]);

            return app(TikTokFeedController::class)->read($request, $id, app(\App\Services\Browser\BrowserTaskQueue::class));
        }
        $account = $this->account($request, $id);
        $max = config('agent.read_limit');
        $limit = $request->validate(['limit' => "nullable|integer|min:1|max:{$max}"])['limit'] ?? 10;

        $state = $this->catalog->forAccount($account)->get($action);
        abort_unless($state && $state['kind'] === 'read' && $state['status'] === 'ready', 404, 'Lecture indisponible sur ce réseau.');
        abort_unless($state['enabled'], 403, 'Action désactivée sur ce compte.');
        abort_unless($account->usesBrowser(), 422, 'Ce compte n’est pas en mode navigateur.');
        abort_if($state['left'] < 1, 429, 'Quota du jour atteint.');

        try {
            return response()->json(['items' => $reader->read($account, $action, $limit), 'left' => $state['left'] - 1]);
        } catch (BrowserException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /** Prépare des réponses dans RS-Max : l'agent ne dépense aucun jeton pour les rédiger. */
    public function prepare(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request, $id);
        $max = config('agent.batch_limit');
        $limit = $request->validate(['limit' => "nullable|integer|min:1|max:{$max}"])['limit'] ?? 10;
        $state = $this->catalog->forAccount($account)->get('reply');

        abort_unless($state && $state['status'] === 'ready', 404, 'Réponses indisponibles sur ce réseau.');
        abort_unless($state['enabled'], 403, 'Action désactivée sur ce compte.');
        abort_unless($account->persona?->is_active && filled($account->persona->system_prompt), 422, 'Persona actif obligatoire pour répondre.');

        PrepareInboxDrafts::dispatch($account->id, $limit);

        return response()->json(['queued' => true], 202);
    }

    /** Ce qui attend un humain dans la messagerie. */
    public function drafts(Request $request): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $data = $request->validate(['account' => 'nullable|integer', 'limit' => 'nullable|integer|min:1|max:50']);
        $visible = $request->user()->visibleSocialAccounts()->select('social_accounts.id');

        $rows = InboxItem::awaitingValidation()->whereIn('social_account_id', $visible)
            ->when($data['account'] ?? null, fn ($q, $id) => $q->where('social_account_id', $id))
            ->orderByDesc('id')->limit($data['limit'] ?? 20)->get(['id', 'social_account_id', 'author_username', 'author_name', 'reply_draft']);

        return response()->json(['drafts' => $rows->map(fn (InboxItem $i) => [
            'id' => $i->id, 'account' => $i->social_account_id, 'author' => $i->author_username ?? $i->author_name, 'reply' => $i->reply_draft,
        ])]);
    }

    public function configure(Request $request, int $id, string $action): JsonResponse
    {
        $account = $this->account($request, $id);
        $definition = $this->catalog->action($account, $action);
        abort_unless($definition && $definition['status'] === 'ready', 404, 'Action indisponible sur ce réseau.');
        $data = $request->validate(['enabled' => 'required|boolean', 'daily_limit' => 'nullable|integer|min:0|max:1000']);

        $setting = $this->catalog->configure($account, $action, $data['enabled'], $data['daily_limit'] ?? $definition['limit']);

        return response()->json(['action' => $action, 'enabled' => $setting->enabled, 'daily_limit' => $setting->daily_limit]);
    }

    public function log(Request $request): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $data = $request->validate(['account' => 'nullable|integer', 'limit' => 'nullable|integer|min:1|max:50']);
        $visible = $request->user()->visibleSocialAccounts()->select('social_accounts.id');

        $rows = BotActionLog::where('source', AgentCatalog::SOURCE)->whereIn('social_account_id', $visible)
            ->when($data['account'] ?? null, fn ($q, $id) => $q->where('social_account_id', $id))
            ->orderByDesc('id')->limit($data['limit'] ?? 20)->get(['social_account_id', 'action_type', 'success', 'performed_at']);

        return response()->json(['log' => $rows->map(fn (BotActionLog $l) => [
            'account' => $l->social_account_id, 'action' => $l->action_type, 'ok' => $l->success, 'at' => $l->performed_at?->toIso8601String(),
        ])]);
    }
}
