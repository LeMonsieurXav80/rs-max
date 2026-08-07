<?php

namespace App\Services\Import\Concerns;

use App\Models\ExternalPost;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Regles communes aux services d'import pour ne ramener que les nouveautes.
 *
 * Les APIs n'offrent pas toutes un filtre par date (`since` chez Meta,
 * `since_id` chez X, rien du tout chez Bluesky ou sur les playlists YouTube).
 * D'ou deux mecanismes complementaires :
 *   - le parametre serveur quand il existe, pour ne pas payer l'appel du tout ;
 *   - l'arret de la pagination des qu'on depasse la fenetre, qui marche partout.
 */
trait ImportsIncrementally
{
    /**
     * Point de reprise d'un compte : sa publication connue la plus recente,
     * moins un recouvrement. Sans historique, on se limite a `first_run_days`
     * pour ne pas rapatrier des annees d'archives.
     */
    protected function importSince(SocialAccount $account): CarbonImmutable
    {
        $latest = ExternalPost::where('social_account_id', $account->id)
            ->max('published_at');

        if (! $latest) {
            return CarbonImmutable::now()->subDays(config('import.first_run_days'));
        }

        return CarbonImmutable::parse($latest)->subHours(config('import.overlap_hours'));
    }

    /**
     * Identifiant de la publication connue la plus recente pour ce compte.
     * Sert aux APIs qui filtrent par id plutot que par date (`since_id` chez X).
     */
    protected function newestKnownExternalId(SocialAccount $account): ?string
    {
        return ExternalPost::where('social_account_id', $account->id)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->value('external_id');
    }

    /**
     * La publication est-elle anterieure a la fenetre ? Sert a couper la
     * pagination : les flux etant rendus du plus recent au plus ancien, le
     * premier « trop vieux » signale qu'il n'y a plus rien a prendre.
     */
    protected function isBeforeWindow(?string $publishedAt, CarbonImmutable $since): bool
    {
        if (! $publishedAt) {
            return false;
        }

        try {
            return CarbonImmutable::parse($publishedAt)->lt($since);
        } catch (\Exception) {
            // Date illisible : on prefere garder la publication que la perdre.
            return false;
        }
    }

    /**
     * Faut-il depenser un appel d'insights pour cette publication ?
     *
     * Non si on la connait deja et que ses statistiques sont soit fraiches,
     * soit definitivement stabilisees par l'age.
     */
    protected function needsMetricsRefresh(?ExternalPost $existing): bool
    {
        if (! $existing) {
            return true;
        }

        $settled = $existing->published_at
            && $existing->published_at->lt(now()->subDays(config('import.metrics_settle_days')));

        if ($settled) {
            return false;
        }

        return ! $existing->metrics_synced_at
            || $existing->metrics_synced_at->lt(now()->subHours(config('import.metrics_ttl_hours')));
    }

    /**
     * Publication deja connue pour ce reseau, si elle existe.
     */
    protected function existingPost(int $platformId, string $externalId): ?ExternalPost
    {
        return ExternalPost::where('platform_id', $platformId)
            ->where('external_id', $externalId)
            ->first();
    }
}
