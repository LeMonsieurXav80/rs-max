<?php

namespace App\Services\Agent;

use App\Models\AccountActionEvent;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Support\Carbon;

final class RepostCadence
{
    public const ACTIONS = ['x.repost', 'x.quote', 'bluesky.repost', 'bluesky.quote'];

    public function hours(SocialAccount $account): int
    {
        return (int) ($account->agent_strategy['repost_interval_hours'] ?? 0);
    }

    public function nextAllowedAt(SocialAccount $account): ?Carbon
    {
        $hours = $this->hours($account);
        if ($hours < 1) {
            return null;
        }

        // Les réservations, y compris incertaines, comptent. Inclure aussi les republications
        // enregistrées dans le compositeur pour tenir compte de l'activité récente du compte.
        $event = AccountActionEvent::where('social_account_id', $account->id)
            ->whereIn('action', self::ACTIONS)->max('updated_at');
        $published = PostPlatform::where('social_account_id', $account->id)->where('status', 'published')
            ->whereHas('post', fn ($q) => $q->where('source_type', 'reshare'))
            ->max('published_at');
        $last = max($event, $published);
        $until = $last ? Carbon::parse($last)->utc()->addHours($hours) : null;

        return $until?->isFuture() ? $until : null;
    }
}
