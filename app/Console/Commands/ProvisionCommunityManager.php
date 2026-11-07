<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class ProvisionCommunityManager extends Command
{
    protected $signature = 'community-manager:provision {--account=* : Comptes attribués explicitement} {--token-file= : Fichier privé du jeton, jamais affiché}';

    protected $description = 'Créer l’accès limité de Community Manager et écrire son jeton dans un fichier privé';

    public function handle(): int
    {
        $ids = array_values(array_unique(array_map('intval', $this->option('account'))));
        if (! $ids || SocialAccount::whereIn('id', $ids)->count() !== count($ids) || ! $this->option('token-file')) {
            $this->error('Comptes existants et chemin de fichier privé obligatoires.');

            return self::FAILURE;
        }
        $user = User::firstOrCreate(['email' => 'community-manager@rs-max.internal'], [
            'name' => 'Community Manager', 'role' => User::ROLE_MANAGER, 'password' => Str::random(64),
        ]);
        if ($user->name !== 'Community Manager' || $user->role !== User::ROLE_MANAGER) {
            $this->error('Cette identité existe déjà avec une autre configuration.');

            return self::FAILURE;
        }
        $user->socialAccounts()->syncWithoutDetaching(array_fill_keys($ids, ['is_active' => true]));
        $file = $this->option('token-file');
        if (is_file($file)) {
            $existing = json_decode(file_get_contents($file), true);
            $token = PersonalAccessToken::findToken($existing['token'] ?? '');
            if (! $token || $token->tokenable_id !== $user->id || $token->abilities !== ['community-manager']) {
                $this->error('Fichier existant non conforme : aucun remplacement automatique.');

                return self::FAILURE;
            }
        } else {
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0700, true);
            }
            $plain = $user->createToken('Community Manager', ['community-manager'])->plainTextToken;
            $temporary = $file.'.'.Str::random(12).'.tmp';
            $previous = umask(0077);
            try {
                file_put_contents($temporary, json_encode(['base_url' => rtrim(config('app.url'), '/'), 'token' => $plain], JSON_THROW_ON_ERROR));
                chmod($temporary, 0600);
                rename($temporary, $file);
            } finally {
                umask($previous);
            }
        }
        $this->line(json_encode(['user_id' => $user->id, 'accounts' => $ids, 'token_file' => $file]));

        return self::SUCCESS;
    }
}
