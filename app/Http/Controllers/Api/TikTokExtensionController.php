<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotActionLog;
use App\Models\BrowserTask;
use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\Browser\BrowserException;
use App\Services\Browser\TikTokSessionService;
use App\Services\Inbox\InboxReplyGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** TikTok reste piloté par Companion ; RS-Max associe le persona et réserve chaque envoi. */
class TikTokExtensionController extends Controller
{
    private function username(Request $request): string
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:24', 'regex:/^[a-zA-Z0-9_.]+$/']]);

        return strtolower($data['username']);
    }

    private function account(Request $request, int $id): SocialAccount
    {
        $account = $request->user()->connectedSocialAccounts()->with(['persona', 'platform'])->find($id);
        abort_unless($account, 403, 'Compte RS-Max non autorisé.');
        abort_unless($account->persona?->is_active && filled($account->persona->system_prompt), 422, 'Persona actif avec ses consignes obligatoire.');

        return $account;
    }

    private function profile(Request $request): object
    {
        $profile = DB::table('extension_tiktok_profiles')->where('username', $this->username($request))->first();
        abort_unless($profile, 422, 'Associe ce profil TikTok à son persona RS-Max.');
        $this->account($request, $profile->social_account_id);

        return $profile;
    }

    private function personaHash(SocialAccount $account): string
    {
        return hash('sha256', $account->id.':'.$account->persona->id.':'.$account->persona->system_prompt);
    }

    public function context(Request $request): JsonResponse
    {
        $username = $this->username($request);
        $profile = DB::table('extension_tiktok_profiles')->where('username', $username)->first();
        $account = $profile ? $this->account($request, $profile->social_account_id) : null;
        $candidates = $request->user()->connectedSocialAccounts()->with(['persona', 'platform'])->get()
            ->filter(fn ($a) => $a->persona?->is_active && filled($a->persona->system_prompt))
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'platform' => $a->platform->slug,
                'persona' => ['id' => $a->persona->id, 'name' => $a->persona->name]])->values();

        return response()->json(['username' => $username, 'account_id' => $account?->id,
            'persona' => $account ? ['id' => $account->persona->id, 'name' => $account->persona->name] : null,
            'accounts' => $candidates]);
    }

    public function comments(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $rows = DB::table('extension_tiktok_comments')->where('profile_id', $profile->id)
            ->orderByDesc('id')->limit(100)->get(['id', 'author', 'content', 'video_context', 'reply_text', 'status', 'liked', 'proof', 'updated_at']);

        return response()->json(['username' => $profile->username, 'comments' => $rows]);
    }

    /** Sans nom déjà connu, seule une fiche TikTok libre et unique peut recevoir la session. */
    private function sessionAccount(Request $request, string $username): SocialAccount
    {
        $accounts = $request->user()->connectedSocialAccounts()->whereRelation('platform', 'slug', 'tiktok')->get();
        $account = $accounts->firstWhere('browser_username', $username);
        if ($account) {
            return $account;
        }

        $free = $accounts->whereNull('browser_username');
        abort_if($free->isEmpty(), 422, 'Crée d’abord un compte TikTok dans RS-Max.');
        abort_if($free->count() > 1, 409, 'Plusieurs comptes TikTok attendent une session : envoie-les un par un, en créant chaque compte juste avant.');

        return $free->first();
    }

    public function session(Request $request, TikTokSessionService $sessions): JsonResponse
    {
        $username = $this->username($request);
        $data = $request->validate([
            'cookies' => 'required|array|min:1|max:200',
            'cookies.*.name' => 'required|string|max:200',
            'cookies.*.value' => 'required|string|max:8000',
            'cookies.*.domain' => ['required', 'string', 'regex:/^\.?(.+\.)?tiktok\.com$/'],
            'cookies.*.path' => 'nullable|string|max:500',
            'cookies.*.expirationDate' => 'nullable|numeric',
            'cookies.*.httpOnly' => 'nullable|boolean',
            'cookies.*.secure' => 'nullable|boolean',
            'cookies.*.sameSite' => 'nullable|string|max:20',
        ]);
        abort_unless($sessions->hasSession($data['cookies']), 422, 'Aucune session TikTok dans ce navigateur.');
        $account = $this->sessionAccount($request, $username);

        try {
            $connected = $sessions->store($account, $data['cookies'], $username);
        } catch (BrowserException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        BotActionLog::create(['social_account_id' => $account->id, 'action_type' => 'tiktok.session', 'source' => 'extension',
            'target_uri' => 'https://www.tiktok.com/@'.$username, 'success' => $connected, 'performed_at' => now()]);

        return response()->json(['status' => $account->browser_session_status, 'username' => $account->browser_username],
            $connected ? 200 : 409);
    }

    public function bind(Request $request): JsonResponse
    {
        $username = $this->username($request);
        $data = $request->validate(['social_account_id' => 'required|integer']);
        $this->account($request, $data['social_account_id']);
        $existing = DB::table('extension_tiktok_profiles')->where('username', $username)->first();
        if ($existing) {
            $this->account($request, $existing->social_account_id);
        }
        DB::table('extension_tiktok_profiles')->updateOrInsert(['username' => $username], [
            'social_account_id' => $data['social_account_id'], 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]);

        return $this->context($request);
    }

    public function suggest(Request $request, InboxReplyGenerator $generator): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validate(['fingerprint' => 'required|string|size:64|regex:/^[a-f0-9]+$/',
            'author' => 'required|string|max:255', 'content' => 'required|string|max:10000',
            'video_context' => 'required|string|max:10000', 'max_length' => 'required|integer|min:1|max:150',
            'browser_task_id' => 'sometimes|required|integer|min:1']);
        abort_if(strtolower(ltrim($data['author'], '@')) === $profile->username, 409, 'Commentaire du compte lui-même.');
        $approvedReply = isset($data['browser_task_id']) ? $this->approvedReply($request, $profile, $data) : null;

        return Cache::lock('tt-suggest:'.$profile->id.':'.$data['fingerprint'], 60)->block(2, function () use ($request, $profile, $data, $generator, $approvedReply) {
            $existing = DB::table('extension_tiktok_comments')->where('profile_id', $profile->id)->where('fingerprint', $data['fingerprint'])->first();
            $account = $this->account($request, $profile->social_account_id);
            if ($existing) {
                abort_unless($existing->persona_hash === $this->personaHash($account), 409, 'Persona modifié depuis la préparation : vérifier le brouillon.');
                abort_unless($existing->status === 'prepared', 409, 'Envoi déjà tenté : vérifier TikTok avant toute reprise.');
                abort_unless($existing->content === $data['content'] && $existing->video_context === $data['video_context'] && $existing->author === $data['author'], 409, 'Empreinte de commentaire ambiguë.');
                $reply = $approvedReply ?? $existing->reply_text;
                abort_if(strlen(mb_convert_encoding($reply, 'UTF-16LE', 'UTF-8')) / 2 > $data['max_length'], 422, 'Brouillon trop long pour cet éditeur.');
                if ($reply !== $existing->reply_text) {
                    $updated = DB::table('extension_tiktok_comments')->where('id', $existing->id)
                        ->where('status', 'prepared')->where('reply_text', $existing->reply_text)
                        ->update(['reply_text' => $reply, 'updated_at' => now()]);
                    abort_unless($updated, 409, 'Le brouillon a changé avant réservation.');
                }

                return response()->json(['id' => $existing->id, 'reply_text' => $reply, 'status' => 'prepared']);
            }
            $item = new InboxItem(['type' => 'comment', 'author_name' => $data['author'], 'content' => $data['content']]);
            $reply = $approvedReply ?? $generator->generate($item, $account, true, $data['max_length'], $data['video_context']);
            abort_unless(filled($reply), 503, 'Génération indisponible : aucun envoi.');
            // La limite textarea est mesurée en unités UTF-16 (les emojis peuvent en occuper deux).
            abort_if(strlen(mb_convert_encoding($reply, 'UTF-16LE', 'UTF-8')) / 2 > $data['max_length'], 422, 'Réponse trop longue : aucun texte tronqué ni envoyé.');
            $id = DB::table('extension_tiktok_comments')->insertGetId(['profile_id' => $profile->id,
                'persona_hash' => $this->personaHash($account), 'fingerprint' => $data['fingerprint'], 'author' => $data['author'], 'content' => $data['content'],
                'video_context' => $data['video_context'], 'reply_text' => $reply, 'status' => 'prepared',
                'created_at' => now(), 'updated_at' => now()]);

            return response()->json(['id' => $id, 'reply_text' => $reply, 'status' => 'prepared',
                'persona' => ['id' => $account->persona->id, 'name' => $account->persona->name]]);
        });
    }

    private function approvedReply(Request $request, object $profile, array $data): string
    {
        abort_unless($request->user()->isAdmin(), 403);
        $task = BrowserTask::where('kind', 'tiktok.reply')->where('status', BrowserTask::RUNNING)->find($data['browser_task_id']);
        abort_unless($task, 409, 'Tâche de réponse TikTok inactive.');

        $account = $this->sessionAccount($request, $profile->username);
        $payload = $task->payload;
        abort_unless($task->social_account_id === $account->id && ($payload['username'] ?? null) === $profile->username, 409, 'Identité de la tâche TikTok différente.');
        abort_unless(($payload['target']['author'] ?? null) === $data['author']
            && ($payload['target']['content'] ?? null) === $data['content']
            && ($payload['target']['caption'] ?? null) === $data['video_context'], 409, 'Commentaire différent de la tâche TikTok.');
        $reply = $payload['text'] ?? null;
        abort_unless(is_string($reply) && filled($reply), 422, 'Réponse validée manquante.');

        return $reply;
    }

    public function begin(Request $request, int $id): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validate(['reply_text' => 'required|string|max:150']);
        $updated = DB::table('extension_tiktok_comments')->where('id', $id)->where('profile_id', $profile->id)
            ->where('status', 'prepared')->where('reply_text', $data['reply_text'])
            ->where('persona_hash', $this->personaHash($this->account($request, $profile->social_account_id)))
            ->update(['status' => 'publishing', 'updated_at' => now()]);
        abort_unless($updated, 409, 'Envoi déjà réservé ou texte différent : aucun nouvel envoi.');

        return response()->json(['status' => 'publishing']);
    }

    public function finish(Request $request, int $id): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validate(['status' => 'required|in:published,uncertain', 'liked' => 'required|boolean', 'proof' => 'required|string|max:255']);
        $updated = DB::table('extension_tiktok_comments')->where('id', $id)->where('profile_id', $profile->id)
            ->where('status', 'publishing')->update($data + ['updated_at' => now()]);
        abort_unless($updated, 409, 'Tentative absente ou déjà terminée.');

        return response()->json(['status' => $data['status']]);
    }
}
