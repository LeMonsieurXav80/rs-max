<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Agent\ApiInboxActions;

/**
 * Mêmes règles sans modèle que `tiktok:scripted-inbox`, sur les réseaux par API (Facebook, Instagram, Threads,
 * YouTube, Bluesky) des comptes dont l'action `reply` est activée : emoji seul ⇒ 🙂/😊/😄, simple merci ⇒ like
 * (Facebook seulement, seul réseau aimé par l'API) ou rien, jamais de réponse. Hermès ne lit plus ces commentaires.
 */
class InboxScriptedCommand extends TikTokScriptedInboxCommand
{
    protected $signature = 'inbox:scripted {--commit : Répondre et aimer pour de vrai} {--limit=30 : Commentaires par compte et par passage}';

    protected $description = 'Répond aux emojis seuls et traite les simples mercis des réseaux par API, sans modèle de langue';

    protected function accounts(): iterable
    {
        return SocialAccount::with('platform')->whereHas('platform', fn ($q) => $q->whereIn('slug', ApiInboxActions::PLATFORMS))
            ->get()->filter(fn (SocialAccount $a) => ! $a->usesBrowser() && $this->enabled($a, 'reply'));
    }

    protected function canLike(SocialAccount $account): bool
    {
        return $account->platform->slug === 'facebook' && $this->enabled($account, 'comment.like');
    }

    private function enabled(SocialAccount $account, string $action): bool
    {
        try {
            app(ApiInboxActions::class)->assertEnabled($account, $action);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
