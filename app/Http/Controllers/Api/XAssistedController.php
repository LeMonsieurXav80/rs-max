<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\TikTokEngagement;
use App\Services\Agent\AutomationJournal;
use App\Services\Agent\XEngagementService;
use App\Services\Agent\XReplyDraft;
use App\Services\Agent\XReplyVault;
use App\Services\Inbox\ReplyLanguage;
use App\Services\Stats\XBrowserStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class XAssistedController extends Controller
{
    private function account(Request $request): SocialAccount
    {
        $identity = $request->validate([
            'platform_account_id' => 'required|string|regex:/^[0-9]{1,25}$/',
            'username' => 'required|string|regex:/^[a-zA-Z0-9_]{1,15}$/',
        ]);
        $account = $request->user()->activeSocialAccounts()->with('persona')
            ->whereRelation('platform', 'slug', 'twitter')
            ->where('platform_account_id', $identity['platform_account_id'])->first();
        abort_unless($account, 403, 'Compte X non attribué dans RS-Max.');
        abort_if(filled($account->browser_username) && strcasecmp($account->browser_username, $identity['username']) !== 0,
            409, 'Le profil X ouvert diffère de celui vérifié dans RS-Max.');

        return $account;
    }

    public function context(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $strategy = $account->agent_strategy ?? [];

        $languages = array_values($account->languages ?? []);

        return response()->json([
            'search_language' => count($languages) === 1 ? $languages[0] : null,
            'reply_language' => ReplyLanguage::code($account),
            'queries' => array_values($strategy['search_queries'] ?? []),
            'exclusions' => array_values($strategy['exclusions'] ?? []),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function check(Request $request, XEngagementService $engagements): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate(['ids' => 'required|array|min:1|max:12', 'ids.*' => 'string|distinct|regex:/^[0-9]{1,25}$/']);
        $blocked = [];
        foreach ($data['ids'] as $id) {
            try {
                $engagements->assertReplyAvailable($account, $id);
            } catch (HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }
                $blocked[] = $id;
            }
        }

        return response()->json(['blocked' => $blocked]);
    }

    public function observations(Request $request, XBrowserStatsService $stats): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate([
            'followers_observation' => 'nullable|array:value,precision,display',
            'followers_observation.value' => 'required_with:followers_observation|integer|min:0',
            'followers_observation.precision' => 'required_with:followers_observation|in:exact,rounded',
            'followers_observation.display' => 'required_with:followers_observation|string|max:100',
            'tweets' => 'present|array|max:100',
            'tweets.*' => 'array:id,author,text,published_at,reply,reposted,classification_known,stats',
            'tweets.*.id' => 'required|string|regex:/^[0-9]{1,25}$/',
            'tweets.*.author' => 'required|string|regex:/^[a-zA-Z0-9_]{1,15}$/',
            'tweets.*.text' => 'present|nullable|string|max:30000',
            'tweets.*.published_at' => 'nullable|date',
            'tweets.*.reply' => 'required|boolean',
            'tweets.*.reposted' => 'required|boolean',
            'tweets.*.classification_known' => 'required|boolean',
            'tweets.*.stats' => 'present|nullable|string|max:2000',
        ]);

        return response()->json($stats->recordPage($account, $request->input('username'), $data));
    }

    public function reserveReaction(Request $request, XEngagementService $engagements): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate([
            'action' => 'required|in:like,repost',
            'target_id' => 'required|string|regex:/^[0-9]{1,25}$/',
            'author' => 'required|string|regex:/^[a-zA-Z0-9_]{1,15}$/',
        ]);
        $author = strtolower($data['author']);
        abort_if($author === strtolower($request->input('username')), 409, 'Publication du compte lui-même.');
        $row = $engagements->reserveCompanionReaction($account, $request->input('username'), ['id' => $data['target_id'], 'author' => $author], $data['action']);

        return response()->json(['id' => $row->id, 'status' => $row->status], 201);
    }

    public function finishReaction(Request $request, int $id, XEngagementService $engagements): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate([
            'status' => 'required|in:confirmed,unchanged,failed,uncertain',
            'target_id' => 'required_if:status,confirmed,unchanged|nullable|string|regex:/^[0-9]{1,25}$/',
            'proof' => 'required_if:status,confirmed,unchanged|nullable|in:visible,after_reload',
            'error' => 'nullable|string|max:1000',
        ]);
        $row = $engagements->finishCompanionReaction($account, $request->input('username'), $id, $data);

        return response()->json(['id' => $row->id, 'status' => $row->status]);
    }

    public function preview(Request $request, XReplyPreviewController $preview, XReplyVault $vault, XReplyDraft $generator): JsonResponse
    {
        $account = $this->account($request);
        $target = $request->validate([
            'target_id' => 'required|string|regex:/^[0-9]{1,25}$/',
            'author' => 'required|string|regex:/^[a-zA-Z0-9_]{1,15}$/',
        ]);
        abort_if(strcasecmp($target['author'], $request->input('username')) === 0, 409, 'Publication du compte lui-même.');
        $data = $preview->preview($request, $account, $vault, $generator, requireVault: false, filterByStrategy: true)->getData(true);
        $key = Str::uuid()->toString();
        Cache::put('x-assisted-draft:'.$key, [
            'account_id' => $account->id,
            'username' => strtolower($request->input('username')),
            'target_id' => $target['target_id'],
            'author' => strtolower($target['author']),
            'persona_hash' => TikTokEngagement::personaHash($account),
            'reply_language' => ReplyLanguage::code($account),
            'reply_text' => $data['reply_text'],
        ], now()->addMinutes(15));

        return response()->json($data + ['draft_key' => $key])->header('Cache-Control', 'no-store, private');
    }

    public function reserve(Request $request, XEngagementService $engagements, AutomationJournal $journal): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate(['draft_key' => 'required|uuid']);
        $draft = Cache::get('x-assisted-draft:'.$data['draft_key']);
        abort_unless($draft && $draft['account_id'] === $account->id && $draft['username'] === strtolower($request->input('username')),
            409, 'Brouillon expiré ou rattaché à un autre compte.');
        abort_unless(XReplyDraft::fits($draft['reply_text']), 422, 'Brouillon trop long pour X. Demande un nouvel aperçu.');

        $id = DB::transaction(function () use ($account, $draft, $engagements, $journal) {
            $locked = SocialAccount::with('persona')->lockForUpdate()->findOrFail($account->id);
            abort_unless($locked->persona?->is_active && $draft['persona_hash'] === TikTokEngagement::personaHash($locked),
                409, 'Persona modifié depuis la préparation du brouillon.');
            abort_unless(($draft['reply_language'] ?? null) === ReplyLanguage::code($locked),
                409, 'Langue de réponse modifiée depuis la préparation du brouillon.');
            $engagements->assertReplyAvailable($locked, $draft['target_id']);
            $existing = DB::table('extension_x_replies')->where('social_account_id', $locked->id)->where('tweet_id', $draft['target_id']);
            $attributes = [
                'author' => $draft['author'],
                'context_json' => json_encode(['assisted' => true, 'username' => $draft['username']], JSON_THROW_ON_ERROR),
                'persona_hash' => $draft['persona_hash'],
                'reply_text' => $draft['reply_text'],
                'status' => 'publishing',
                'proof' => 'manual:reserved',
                'updated_at' => now(),
            ];
            if ($row = $existing->first()) {
                $existing->update($attributes);
                $id = $row->id;
            } else {
                $id = DB::table('extension_x_replies')->insertGetId($attributes + [
                    'social_account_id' => $locked->id, 'tweet_id' => $draft['target_id'], 'created_at' => now(),
                ]);
            }
            $journal->xCompanionReply(DB::table('extension_x_replies')->find($id));

            return $id;
        });

        return response()->json(['id' => $id, 'status' => 'reserved']);
    }

    public function finish(Request $request, int $id, AutomationJournal $journal): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate(['reply_id' => 'nullable|string|regex:/^[0-9]{1,25}$/']);
        $status = DB::transaction(function () use ($account, $request, $id, $data, $journal) {
            $table = DB::table('extension_x_replies')->where('id', $id)->where('social_account_id', $account->id);
            $row = $table->lockForUpdate()->first();
            $context = $row ? json_decode($row->context_json, true) : null;
            abort_unless($context['assisted'] ?? false, 404);
            abort_unless($context['username'] === strtolower($request->input('username')), 409, 'Profil X changé.');
            abort_if(($data['reply_id'] ?? null) === $row->tweet_id, 422, 'Identifiant de réponse identique à la cible.');
            $proof = isset($data['reply_id']) ? 'manual:visible:'.$data['reply_id'] : 'manual:unconfirmed';
            $status = isset($data['reply_id']) ? 'published' : 'uncertain';
            if ($row->status === $status && $row->proof === $proof) {
                return $status;
            }
            abort_unless($row->status === 'publishing' && $row->proof === 'manual:reserved', 409, 'Tentative déjà terminée.');
            $change = ['status' => $status, 'proof' => $proof, 'updated_at' => now()];
            $table->update($change);
            $journal->xCompanionReply((object) array_replace((array) $row, $change));

            return $status;
        });

        return response()->json(['status' => $status]);
    }
}
