<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Twitter\TwitterArticleService;
use Illuminate\Console\Command;

/**
 * Sonde les endpoints Articles de X avec un vrai compte, avant de bâtir
 * la fonctionnalité dessus. Répond à deux questions d'un coup : le palier
 * d'API souscrit donne-t-il accès aux Articles, et l'abonnement du compte
 * suffit-il ?
 *
 * Ne publie RIEN sans --publish : par défaut, on s'arrête au brouillon.
 */
class TestTwitterArticleCommand extends Command
{
    protected $signature = 'twitter:test-article
                            {--account= : ID du compte social X à utiliser}
                            {--title=Test API Articles : Titre de l\'article}
                            {--body= : Corps de l\'article (défaut : un texte de test multi-blocs)}
                            {--publish : Publie réellement le brouillon sur le compte (irréversible)}';

    protected $description = 'Teste les endpoints Articles de X (brouillon, puis publication si --publish)';

    public function handle(TwitterArticleService $articles): int
    {
        $account = $this->resolveAccount();

        if (! $account) {
            return self::FAILURE;
        }

        $this->line("Compte : <info>{$account->name}</info> (#{$account->id})");
        $this->line('Abonnement : <info>'.($account->subscription_type ?? 'inconnu').'</info>');

        if (! $account->hasPaidSubscription()) {
            $this->warn('Ce compte n\'est pas détecté comme abonné. Les Articles exigent un abonnement Premium ;');
            $this->warn('lancez d\'abord « Tester la connexion » sur la page X pour renseigner subscription_type.');

            if (! $this->confirm('Tenter quand même ?', false)) {
                return self::FAILURE;
            }
        }

        $body = $this->option('body') ?: $this->defaultBody();
        $contentState = $articles->toContentState($body);

        $this->newLine();
        $this->line('content_state envoyé :');
        $this->line(json_encode($contentState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();

        // ── Brouillon ────────────────────────────────────────────
        $this->line('POST /2/articles/draft ...');
        $draft = $articles->createDraft($account, $this->option('title'), $body);

        if (! $draft['success']) {
            $this->error('Échec du brouillon : '.$draft['error']);
            $this->dumpResponse($draft);
            $this->newLine();
            $this->line('Un 403 / « client-not-enrolled » indique un palier d\'API sans accès aux Articles.');

            return self::FAILURE;
        }

        $articleId = $draft['article_id'];
        $this->info("Brouillon créé : article_id = {$articleId}");

        // ── Publication ──────────────────────────────────────────
        if (! $this->option('publish')) {
            $this->newLine();
            $this->line('Brouillon uniquement — rien n\'a été publié.');
            $this->line("Pour aller au bout : <comment>php artisan twitter:test-article --account={$account->id} --publish</comment>");

            return self::SUCCESS;
        }

        if (! $this->confirm("Publier réellement cet article sur « {$account->name} » ? C'est public et irréversible.", false)) {
            $this->line('Annulé. Le brouillon reste sur le compte.');

            return self::SUCCESS;
        }

        $this->line("POST /2/articles/{$articleId}/publish ...");
        $published = $articles->publishDraft($account, $articleId);

        if (! $published['success']) {
            $this->error('Échec de la publication : '.$published['error']);
            $this->dumpResponse($published);

            return self::FAILURE;
        }

        $this->info('Article publié. post_id = '.$published['post_id']);

        return self::SUCCESS;
    }

    private function resolveAccount(): ?SocialAccount
    {
        $query = SocialAccount::with('platform')
            ->whereHas('platform', fn ($q) => $q->where('slug', 'twitter'));

        if ($id = $this->option('account')) {
            $account = $query->find($id);

            if (! $account) {
                $this->error("Compte X #{$id} introuvable.");

                return null;
            }

            return $account;
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->error('Aucun compte X configuré.');

            return null;
        }

        if ($accounts->count() === 1) {
            return $accounts->first();
        }

        $this->line('Comptes X disponibles :');
        foreach ($accounts as $account) {
            $this->line("  #{$account->id} — {$account->name} (".($account->subscription_type ?? 'abonnement inconnu').')');
        }
        $this->error('Précisez --account=ID.');

        return null;
    }

    private function dumpResponse(array $result): void
    {
        if (! empty($result['response'])) {
            $this->line(json_encode($result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    private function defaultBody(): string
    {
        return <<<'TXT'
        # Test depuis rs-max

        Ce brouillon vérifie l'accès aux endpoints Articles de l'API X.

        ## Ce qui est testé

        - la conversion texte vers DraftJS
        - la signature OAuth 1.0a sur /2/articles
        - le palier d'API et l'abonnement du compte

        > Généré par php artisan twitter:test-article
        TXT;
    }
}
