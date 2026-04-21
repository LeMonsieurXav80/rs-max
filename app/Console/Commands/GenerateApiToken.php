<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GenerateApiToken extends Command
{
    protected $signature = 'api:token
                            {--user= : ID ou email de l\'utilisateur}
                            {--name=claude-code : Nom du token}
                            {--revoke : Révoquer tous les tokens de l\'utilisateur}';

    protected $description = 'Générer ou révoquer un token API Sanctum';

    public function handle(): int
    {
        $userInput = $this->option('user');

        if (! $userInput) {
            // Lister les utilisateurs pour sélection
            $users = User::all(['id', 'name', 'email', 'role']);
            $this->table(['ID', 'Nom', 'Email', 'Rôle'], $users->toArray());
            $userInput = $this->ask('ID ou email de l\'utilisateur');
        }

        $user = is_numeric($userInput)
            ? User::find($userInput)
            : User::where('email', $userInput)->first();

        if (! $user) {
            $this->error("Utilisateur non trouvé : {$userInput}");

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $count = $user->tokens()->count();
            $user->tokens()->delete();
            $this->info("✓ {$count} token(s) révoqué(s) pour {$user->name}");

            return self::SUCCESS;
        }

        $tokenName = $this->option('name');
        $token = $user->createToken($tokenName);

        $this->newLine();
        $this->info("✓ Token créé pour {$user->name} ({$user->email})");
        $this->newLine();
        $this->warn('Token (à conserver, il ne sera plus affiché) :');
        $this->line($token->plainTextToken);
        $this->newLine();

        return self::SUCCESS;
    }
}
