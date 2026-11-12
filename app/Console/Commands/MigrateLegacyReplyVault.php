<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\VaultSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyReplyVault extends Command
{
    protected $signature = 'vault:migrate-legacy {--commit}';

    protected $description = 'Remplacer les réglages x_reply_vault_account_* par des sources du Vault rattachées aux comptes';

    public function handle(): int
    {
        $legacy = Setting::where('key', 'like', 'x_reply_vault_account_%')->get();
        foreach ($legacy as $setting) {
            $id = (int) substr($setting->key, strlen('x_reply_vault_account_'));
            $profile = json_decode((string) $setting->value, true);
            $account = SocialAccount::find($id);
            if (! $account || ! is_array($profile) || ! preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $profile['corpus'] ?? '')) {
                $this->warn("{$setting->key} ignoré : compte ou corpus invalide.");

                continue;
            }
            $this->line("{$account->name} (#{$id}) → fiches PubMed de « Recherches », Vault {$profile['corpus']}");
            if (! $this->option('commit')) {
                continue;
            }
            DB::transaction(function () use ($account, $profile, $setting) {
                $source = VaultSource::firstOrCreate(['corpus' => $profile['corpus'], 'format' => 'pubmed', 'name' => 'Fiches PubMed '.$profile['corpus']],
                    ['folders' => ['Recherches'], 'brands' => $profile['brands'] ?? [], 'is_active' => true]);
                $source->socialAccounts()->syncWithoutDetaching([$account->id]);
                $setting->delete();
            });
        }
        if (! $this->option('commit')) {
            $this->info('Aperçu seulement : relancer avec --commit.');
        }

        return self::SUCCESS;
    }
}
