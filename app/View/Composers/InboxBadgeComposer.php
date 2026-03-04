<?php

namespace App\View\Composers;

use App\Models\InboxItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class InboxBadgeComposer
{
    public function compose(View $view): void
    {
        $user = Auth::user();

        if (! $user) {
            $view->with('inboxUnreadCount', 0);

            return;
        }

        $count = Cache::remember("inbox_unread_{$user->id}", 60, function () use ($user) {
            $query = InboxItem::where('status', 'unread');

            if (! $user->is_admin) {
                $accountIds = $user->socialAccounts()->pluck('social_accounts.id');
                $query->whereIn('social_account_id', $accountIds);
            }

            return $query->count();
        });

        $view->with('inboxUnreadCount', $count);
    }
}
