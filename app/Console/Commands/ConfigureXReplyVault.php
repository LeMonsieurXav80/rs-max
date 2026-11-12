<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Models\VaultSource;
use Illuminate\Console\Command;

class ConfigureXReplyVault extends Command
{
    protected $signature = 'x:configure-reply-vault {corpus} {--account=*} {--brand=*}';

    protected $description = 'Rattacher des comptes aux fiches PubMed (Recherches) d’un Vault ; les autres sources se gèrent dans /vault-sources';

    public function handle(): int
    {
        $corpus = $this->argument('corpus');
        $ids = array_unique($this->option('account'));
        $brands = array_values(array_unique(array_filter(array_map('trim', $this->option('brand')))));
        if (count($brands) > 20 || collect($brands)->contains(fn ($name) => mb_strlen($name) > 100)) {
            $this->error('Liste de marques invalide.');

            return self::FAILURE;
        }
        if (! preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $corpus) || ! $ids) {
            $this->error('Nom de corpus ou comptes invalides.');

            return self::FAILURE;
        }

        $accounts = SocialAccount::with('persona')->whereRelation('platform', 'slug', 'twitter')->whereKey($ids)->get();
        if ($accounts->count() !== count($ids) || $accounts->contains(fn ($account) => ! $account->persona?->is_active)) {
            $this->error('Chaque compte doit être sur X et avoir un persona actif.');

            return self::FAILURE;
        }
        $source = VaultSource::updateOrCreate(['corpus' => $corpus, 'format' => 'pubmed', 'name' => 'Fiches PubMed '.$corpus],
            ['folders' => ['Recherches'], 'brands' => $brands, 'is_active' => true]);
        $source->socialAccounts()->syncWithoutDetaching($accounts->pluck('id')->all());

        return self::SUCCESS;
    }
}
