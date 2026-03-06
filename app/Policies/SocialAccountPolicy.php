<?php

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;

class SocialAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SocialAccount $account): bool
    {
        return $user->isAdmin() || $account->users()->where('user_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, SocialAccount $account): bool
    {
        return $user->isAdmin() || $account->users()->where('user_id', $user->id)->exists();
    }

    public function delete(User $user, SocialAccount $account): bool
    {
        return $user->isAdmin();
    }

    public function toggleActive(User $user, SocialAccount $account): bool
    {
        return $user->isAdmin() || $account->users()->where('user_id', $user->id)->exists();
    }
}
