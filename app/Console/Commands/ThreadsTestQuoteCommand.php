<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Sonde l'API Threads pour la citation native (`quote_post_id`).
 *
 * La doc Meta documente `quote_post_id` sur la creation du conteneur mais ne dit
 * rien de sa combinaison avec `reply_to_id` — or un segment de boost est au
 * milieu d'un fil, donc a la fois une reponse ET une citation. Cette commande
 * repond a la question sans publier quoi que ce soit par defaut : elle cree les
 * conteneurs et lit leur statut, ce qui reste invisible du public.
 *
 * Meme esprit que `twitter:test-article`.
 */
class ThreadsTestQuoteCommand extends Command
{
    protected $signature = 'threads:test-quote
        {--account= : ID du social_account Threads}
        {--quote= : media id du post a citer}
        {--reply= : media id du post auquel repondre (teste la combinaison)}
        {--publish : va jusqu\'a la publication reelle — cree un vrai post public}';

    protected $description = 'Verifie ce que l\'API Threads accepte pour une citation native (quote_post_id)';

    private const API_BASE = 'https://graph.threads.net/v1.0';

    public function handle(): int
    {
        $account = SocialAccount::find($this->option('account'));

        if (! $account || $account->platform->slug !== 'threads') {
            $this->error('Compte Threads introuvable (--account).');

            return self::FAILURE;
        }

        $quoteId = $this->option('quote');
        if (! $quoteId) {
            $this->error('--quote est requis (media id du post a citer).');

            return self::FAILURE;
        }

        $userId = $account->credentials['user_id'];
        $token = $account->credentials['access_token'];
        $replyId = $this->option('reply');

        $cases = [
            'citation seule' => ['quote_post_id' => $quoteId],
        ];

        if ($replyId) {
            $cases['reponse seule'] = ['reply_to_id' => $replyId];
            $cases['reponse + citation'] = ['reply_to_id' => $replyId, 'quote_post_id' => $quoteId];
        }

        $ok = [];

        foreach ($cases as $label => $extra) {
            $params = array_merge([
                'text' => '[sonde rs-max] '.$label,
                'media_type' => 'TEXT',
                'access_token' => $token,
            ], $extra);

            $response = Http::post(self::API_BASE."/{$userId}/threads", $params);
            $containerId = $response->json('id');

            if (! $containerId) {
                $this->line(sprintf('  <fg=red>REFUSE</> %-22s %s', $label, json_encode($response->json('error'), JSON_UNESCAPED_UNICODE)));

                continue;
            }

            // Le conteneur peut etre accepte puis partir en ERROR : c'est la que
            // Threads recale la plupart des combinaisons invalides.
            $status = Http::get(self::API_BASE."/{$containerId}", [
                'fields' => 'status,error_message',
                'access_token' => $token,
            ]);

            $state = $status->json('status');
            $colour = $state === 'FINISHED' ? 'green' : 'red';

            $this->line(sprintf(
                '  <fg=%s>%-8s</> %-22s conteneur %s%s',
                $colour,
                $state ?? 'INCONNU',
                $label,
                $containerId,
                $status->json('error_message') ? ' — '.$status->json('error_message') : '',
            ));

            if ($state === 'FINISHED') {
                $ok[$label] = $containerId;
            }
        }

        if (! $this->option('publish')) {
            $this->newLine();
            $this->info('Aucune publication (--publish pour aller au bout). Les conteneurs expirent seuls.');

            return self::SUCCESS;
        }

        foreach ($ok as $label => $containerId) {
            $published = Http::post(self::API_BASE."/{$userId}/threads_publish", [
                'creation_id' => $containerId,
                'access_token' => $token,
            ]);

            $mediaId = $published->json('id');

            if (! $mediaId) {
                $this->line(sprintf('  <fg=red>ECHEC PUBLI</> %-22s %s', $label, json_encode($published->json('error'), JSON_UNESCAPED_UNICODE)));

                continue;
            }

            $permalink = Http::get(self::API_BASE."/{$mediaId}", [
                'fields' => 'permalink',
                'access_token' => $token,
            ])->json('permalink');

            $this->line(sprintf('  <fg=green>PUBLIE</> %-22s %s', $label, $permalink ?? $mediaId));
        }

        return self::SUCCESS;
    }
}
