<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SocialAccount;
use Illuminate\Console\Command;

class ConfigureXReplyVault extends Command
{
    protected $signature = 'x:configure-reply-vault {corpus} {--account=*} {--brand=*}';

    protected $description = 'Associer les comptes X sélectionnés à un Vault interrogé en lecture';

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
        foreach ($accounts as $account) {
            Setting::set("x_reply_vault_account_{$account->id}", json_encode(['corpus' => $corpus, 'persona_id' => $account->persona_id, 'brands' => $brands], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
