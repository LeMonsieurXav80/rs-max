<?php

namespace App\Services\Agent;

use App\Models\BrowserTask;
use App\Models\MediaFile;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\MediaPublicationTracker;
use App\Services\PartnerTagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/** Photos natives Studio ; la tâche persistée fait aussi office d'intention anti-doublon. */
class TikTokPhotoPublisher
{
    public function media(array $ids): array
    {
        $found = MediaFile::with('folder')->whereIn('id', $ids)->get()->keyBy('id');

        $media = array_map(function ($id) use ($found) {
            $m = $found->get($id);
            abort_unless($m && $m->intimacy_level === 'public' && ! $m->folder?->isEffectivelyPrivate(), 422, 'Photo absente, privée ou interdite de publication.');
            $path = storage_path('app/private/media/'.$m->filename);
            abort_unless(basename($m->filename) === $m->filename && is_file($path) && in_array($m->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true), 422, 'Photo locale JPEG, PNG ou WebP requise.');
            $size = filesize($path);
            abort_unless($size > 0 && $size <= 50 * 1024 * 1024, 422, 'Photo vide ou supérieure à 50 Mo.');

            return ['id' => $m->id, 'name' => $m->filename, 'mimetype' => $m->mime_type, 'size' => $size, 'sha256' => hash_file('sha256', $path), 'url' => '/media/'.$m->filename];
        }, $ids);
        abort_if(array_sum(array_column($media, 'size')) > 250 * 1024 * 1024, 422, 'Le lot dépasse 250 Mo.');

        return $media;
    }

    public function submit(User $user, SocialAccount $account, array $data): array
    {
        $intent = array_intersect_key($data, array_flip(['media_ids', 'title', 'caption', 'visibility', 'rehearse']));
        $intent = ['media_ids' => array_map('intval', $intent['media_ids']), 'title' => $intent['title'] ?? '', 'caption' => str_replace(["\r\n", "\r"], "\n", $intent['caption']), 'visibility' => $intent['visibility'], 'rehearse' => (bool) ($intent['rehearse'] ?? false)];
        $hash = hash('sha256', json_encode($intent, JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($user, $account, $data, $intent, $hash) {
            $account = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $key = 'tt-photo:'.($data['request_key'] ?? 'preview');
            if (! ($data['dry_run'] ?? true) && ($old = BrowserTask::where('social_account_id', $account->id)->where('idempotency_key', $key)->first())) {
                abort_unless(($old->payload['intent_hash'] ?? null) === $hash, 409, 'Cette clé désigne déjà un autre contenu.');

                return $this->state($old);
            }
            if (! ($data['dry_run'] ?? true) && ! $intent['rehearse'] && ($old = BrowserTask::where('social_account_id', $account->id)->where('kind', 'tiktok.photo.publish')->where('payload->intent_hash', $hash)->whereIn('status', ['pending', 'running', 'done', 'uncertain'])->where('created_at', '>=', now()->subDay())->first())) {
                return $this->state($old);
            }
            $media = $this->media($intent['media_ids']);
            abort_unless($account->usesBrowser() && filled($account->browser_profile) && $account->browser_session_status === 'connected', 422, 'Session TikTok NAS active requise.');
            if ($data['dry_run'] ?? true) {
                return ['dry_run' => true, 'account_id' => $account->id, 'media' => $media, 'title' => $intent['title'], 'caption' => $intent['caption'], 'visibility' => $intent['visibility']];
            }
            $window = app(CommunityManagerWindow::class);
            $window->assertOpen();
            if (! $intent['rehearse']) {
                $action = app(AgentCatalog::class)->forAccount($account)->get('photo.publish');
                abort_unless($action && $action['enabled'], 403, 'Publication photo désactivée pour ce compte.');
                abort_if($action['left'] < 1, 429, 'Quota quotidien de publications photo atteint.');
                $cadence = app(AccountActionBudget::class)->usage($account);
                abort_if($cadence['blocked'] || $cadence['remaining'] === 0 || $cadence['daily']['remaining'] === 0 || $cadence['next_allowed_at'], 429, 'Cadence photo indisponible : attendre la prochaine plage autorisée.');
            }
            $payload = $intent + ['url' => 'https://www.tiktok.com/tiktokstudio/upload', 'username' => $account->browser_username,
                'intent_hash' => $hash, 'dry' => $intent['rehearse'], 'activity_window' => $window->specification(), 'deadline' => now()->addMinutes(20)->toIso8601String()];
            $payload['media'] = array_map(fn ($m) => array_replace($m, ['url' => URL::temporarySignedRoute('media.show', now()->addHours(2), ['filename' => $m['name']])]), $media);
            if (! $intent['rehearse']) {
                $post = Post::create(['user_id' => $user->id, 'content_fr' => trim($intent['title']."\n\n".$intent['caption']), 'media' => $media, 'auto_translate' => false, 'source_type' => 'manual', 'status' => 'publishing']);
                $pp = PostPlatform::create(['post_id' => $post->id, 'social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'status' => 'publishing']);
                app(PartnerTagService::class)->syncPost($post);
                $payload['post_platform_id'] = $pp->id;
            }
            $task = BrowserTask::create(['social_account_id' => $account->id, 'profile' => $account->browser_profile, 'kind' => 'tiktok.photo.publish', 'status' => BrowserTask::PENDING, 'idempotency_key' => $key, 'payload' => $payload]);

            return $this->state($task);
        });
    }

    public function claim(BrowserTask $task): void
    {
        if ($task->kind !== 'tiktok.photo.publish') {
            return;
        }
        $p = $task->payload;
        $account = $task->socialAccount;
        abort_unless(app(CommunityManagerWindow::class)->isOpen($p['activity_window']), 423, 'Plage photo terminée.');
        abort_unless($account->usesBrowser() && $account->browser_profile === $task->profile && $account->browser_username === $p['username'] && $account->browser_session_status === 'connected', 409, 'Compte ou session photo modifié.');
        abort_if(now()->greaterThan($p['deadline']), 409, 'Demande photo périmée.');
        abort_if($account->agent_hourly_limit === 0 || $account->agent_daily_limit === 0, 429, 'Compte mis en pause.');
        $current = $this->media($p['media_ids']);
        abort_unless(array_column($current, 'sha256') === array_column($p['media'], 'sha256'), 409, 'Les photos ont changé depuis la demande.');
        if (! $p['dry']) {
            $action = app(AgentCatalog::class)->forAccount($account)->get('photo.publish');
            abort_unless($action && $action['enabled'], 403, 'Publication photo désactivée.');
        }
    }

    public function finish(BrowserTask $task): void
    {
        if ($task->kind !== 'tiktok.photo.publish' || $task->payload['dry'] || ! in_array($task->status, ['done', 'failed', 'uncertain', 'cancelled'], true)) {
            return;
        }
        DB::transaction(function () use ($task) {
            $pp = PostPlatform::whereKey($task->payload['post_platform_id'])->lockForUpdate()->firstOrFail();
            if ($pp->status === 'published') {
                return;
            }
            $r = $task->result ?? [];
            $confirmed = $task->status === 'done' && ($r['confirmed'] ?? false) === true && ($r['proof'] ?? null) === 'after_reload'
                && ($r['username'] ?? null) === $task->payload['username'] && ($r['caption'] ?? null) === $task->payload['caption']
                && ($r['title'] ?? '') === $task->payload['title'] && ($r['media_count'] ?? null) === count($task->payload['media'])
                && ($r['visibility'] ?? null) === $task->payload['visibility']
                && ($r['upload_order_verified'] ?? false) === true && ($r['source_hashes'] ?? []) === array_column($task->payload['media'], 'sha256')
                && preg_match('~^https://www\\.tiktok\\.com/@'.preg_quote($task->payload['username'], '~').'/photo/([0-9]+)$~', $r['url'] ?? '', $match);
            if ($task->status === 'done' && ! $confirmed) {
                $task->update(['status' => 'uncertain', 'error' => 'Preuve photo native après rechargement absente ou différente.']);
            }
            $pp->update(['status' => $confirmed ? 'published' : 'failed', 'external_id' => $confirmed ? $match[1] : null, 'platform_url' => $confirmed ? $r['url'] : null, 'published_at' => $confirmed ? now() : null, 'error_message' => $confirmed ? null : ($task->error ?: 'Publication photo non confirmée : aucun renvoi.')]);
            $pp->post->update(['status' => $confirmed ? 'published' : 'failed', 'published_at' => $confirmed ? now() : null]);
            if ($confirmed) {
                app(MediaPublicationTracker::class)->track($pp->post->media, postId: $pp->post_id, postPlatformId: $pp->id, socialAccountId: $pp->social_account_id, externalUrl: $r['url'], context: 'TikTok photos natives Companion NAS — '.$task->payload['visibility']);
            }
        });
    }

    public function state(BrowserTask $task): array
    {
        $expires = \Illuminate\Support\Carbon::parse($task->payload['deadline'])->addMinutes($task->status === 'running' ? 5 : 0);
        if (in_array($task->status, ['pending', 'running'], true) && now()->greaterThan($expires)) {
            $task->update(['status' => $task->status === 'pending' ? 'cancelled' : 'uncertain', 'finished_at' => now(), 'error' => 'Demande photo périmée : contrôler sans renvoyer.']);
            $this->finish($task);
            app(AccountActionBudget::class)->finish($task);
        }

        return ['id' => $task->id, 'status' => $task->status, 'confirmed' => $task->status === 'done' && ($task->result['confirmed'] ?? false), 'rehearsed' => (bool) ($task->result['rehearsed'] ?? false), 'post_platform_id' => $task->payload['post_platform_id'] ?? null, 'result' => $task->result, 'error' => $task->error];
    }

    public function checkpoint(BrowserTask $task, array $result): void
    {
        abort_unless($task->kind === 'tiktok.photo.publish' && ! $task->payload['dry'] && $task->status === 'running' && $task->claimed_at, 409);
        foreach (['username', 'title', 'caption', 'visibility'] as $key) {
            abort_unless(($result[$key] ?? null) === $task->payload[$key], 409, 'Réservation photo différente.');
        }
        abort_unless(($result['send_clicked'] ?? false) === true && ($result['upload_order_verified'] ?? false) === true
            && ($result['source_hashes'] ?? []) === array_column($task->payload['media'], 'sha256')
            && ($result['media_count'] ?? null) === count($task->payload['media'])
            && is_int($result['started_at'] ?? null) && $result['started_at'] >= $task->claimed_at->getTimestampMs() - 5000 && $result['started_at'] <= now()->getTimestampMs() + 5000, 409, 'Réservation photo non vérifiable.');
        $task->update(['result' => array_intersect_key($result, array_flip(['username', 'title', 'caption', 'visibility', 'send_clicked', 'upload_order_verified', 'source_hashes', 'media_count', 'started_at']))]);
    }

    public function verify(BrowserTask $task, bool $dry = true): array
    {
        abort_unless($task->kind === 'tiktok.photo.publish' && ! $task->payload['dry'] && in_array($task->status, ['done', 'uncertain'], true)
            && ($task->result['send_clicked'] ?? false) === true && isset($task->result['started_at']), 409, 'Aucun envoi photo à vérifier.');
        $payload = array_merge($task->payload, ['dry' => true, 'started_at' => $task->result['started_at'], 'photo_url' => $task->result['url'] ?? null,
            'activity_window' => app(CommunityManagerWindow::class)->specification(), 'deadline' => now()->addMinutes(5)->toIso8601String()]);
        $result = app(\App\Services\Browser\BrowserTaskQueue::class)->run($task->socialAccount, 'tiktok.photo.check', $payload, 180);
        if ($dry || ($result['confirmed'] ?? false) !== true) {
            return ['dry_run' => $dry, 'result' => $result, 'sent' => false];
        }

        return DB::transaction(function () use ($task, $result) {
            $task = BrowserTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $task->update(['status' => 'done', 'result' => array_merge($task->result, $result), 'error' => null]);
            $this->finish($task);
            app(AccountActionBudget::class)->finish($task);

            return $this->state($task) + ['sent' => false];
        });
    }
}
