<?php

namespace App\Policies;

use App\Models\BotTargetAccount;
use App\Models\User;

class BotTargetAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isManager();
    }

    public function view(User $user, BotTargetAccount $target): bool
    {
        return $user->isAdmin()
            || $target->socialAccount->users()->where('user_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function update(User $user, BotTargetAccount $target): bool
    {
        return $user->isAdmin()
            || $target->socialAccount->users()->where('user_id', $user->id)->exists();
    }

    public function delete(User $user, BotTargetAccount $target): bool
    {
        return $user->isAdmin()
            || $target->socialAccount->users()->where('user_id', $user->id)->exists();
    }
}
