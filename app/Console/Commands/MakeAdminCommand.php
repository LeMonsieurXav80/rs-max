<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class MakeAdminCommand extends Command
{
    protected $signature = 'make:admin';
    protected $description = 'Creer un compte administrateur';

    public function handle(): int
    {
        $name = $this->ask('Nom');
        $email = $this->ask('Email');
        $password = $this->secret('Mot de passe');

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => User::ROLE_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->info("Admin '{$user->name}' cree avec succes (ID: {$user->id}).");

        return self::SUCCESS;
    }
}
