<?php

namespace App\Services\Agent;

use App\Models\AccountActionEvent;
use App\Models\InboxItem;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Les activations et tentatives API suivent le même budget que les gestes NAS. */
class ApiInboxActions
{
    public const PLATFORMS = ['facebook', 'instagram', 'threads', 'youtube'];

    public function assertEnabled(SocialAccount $account, string $action): void
    {
        $state = app(AgentCatalog::class)->forAccount($account)->get($action);
        abort_unless($state && $state['enabled'] && $state['status'] === 'ready', 403, 'Action désactivée pour ce compte.');
        abort_if($state['left'] < 1, 429, 'Quota quotidien de cette action atteint.');
    }

    public function likeFacebook(InboxItem $item, bool $dry = true): array
    {
        $account = $item->socialAccount;
        abort_unless($account->platform->slug === 'facebook' && in_array($item->type, ['comment', 'reply']), 422);
        abort_if($item->author_external_id && $item->author_external_id === $account->platform_account_id, 409);
        if ($item->liked_at) {
            return ['success' => true, 'unchanged' => true, 'confirmed' => true, 'dry_run' => $dry];
        }
        $this->assertEnabled($account, 'comment.like');
        app(CommunityManagerWindow::class)->assertOpen();
        if ($dry) {
            return ['dry_run' => true, 'sent' => false, 'item_id' => $item->id, 'action' => 'comment.like'];
        }
        $lock = Cache::lock('inbox-like:'.$item->id, 90);
        abort_unless($lock->get(), 409, 'Like déjà en cours.');
        try {
            $previous = AccountActionEvent::where('social_account_id', $account->id)->where('action', 'facebook.comment.like')->where('target', (string) $item->id)->first();
            if ($previous) {
                if ($previous->status === 'confirmed' || $previous->status === 'unchanged') {
                    $item->update(['liked_at' => now()]);

                    return ['success' => true, 'confirmed' => true, 'unchanged' => true];
                }
                abort(409, 'Like déjà tenté : vérifier le résultat, sans renvoi.');
            }
            $event = app(AccountActionBudget::class)->reserve($account, 'facebook.comment.like', 'api', (string) $item->id);
            $token = $account->credentials['access_token'];
            $url = 'https://graph.facebook.com/v21.0/'.$item->external_id;
            $sent = false;
            try {
                $before = Http::timeout(20)->withToken($token)->get($url, ['fields' => 'id,user_likes']);
                if (! $before->successful()) {
                    throw new \RuntimeException($before->json('error.message', 'Lecture du commentaire refusée.'));
                }
                $unchanged = $before->json('user_likes') === true;
                if (! $unchanged) {
                    $sent = true;
                    $response = Http::timeout(20)->withToken($token)->asForm()->post($url.'/likes');
                    if (! $response->successful() || $response->json('success') !== true) {
                        throw new \RuntimeException($response->json('error.message', 'Like non confirmé.'));
                    }
                }
                $proof = Http::timeout(20)->withToken($token)->get($url, ['fields' => 'id,user_likes']);
                if (! $proof->successful() || $proof->json('user_likes') !== true) {
                    throw new \RuntimeException('Like absent à la relecture API.');
                }
                $item->update(['liked_at' => now()]);
                $event->update(['status' => $unchanged ? 'unchanged' : 'confirmed']);

                return ['success' => true, 'confirmed' => true, 'proof' => 'api_readback', 'unchanged' => $unchanged];
            } catch (\Throwable $e) {
                $event->update(['status' => $sent ? 'uncertain' : 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

                return ['success' => false, 'uncertain' => $sent, 'error' => $e->getMessage()];
            }
        } finally {
            $lock->release();
        }
    }
}
