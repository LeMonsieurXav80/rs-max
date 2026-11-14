<?php

namespace App\Services\Agent\Feed;

use App\Models\SocialAccount;

/** Réglages de l'engagement du fil « Pour vous » par script, rangés dans `agent_strategy.feed_engagement`. */
final class FeedEngagementSettings
{
    public const DEFAULTS = [
        'enabled' => false,
        'spin_templates' => [],
        'emoji_templates' => [],
        'spin_percent' => 40,
        'like_scope' => 'keywords',
        'daily_likes' => 150,
        'daily_spin_comments' => 20,
        'daily_llm_comments' => 40,
        'read_limit' => 25,
        'pass_max_actions' => 8,
    ];

    public const TEMPLATE_LISTS = ['spin_templates', 'emoji_templates'];

    /** Réglages qui élargissent ce que le script fait : réservés à un accès gestionnaire complet. */
    public const RAISING = ['spin_percent', 'daily_likes', 'daily_spin_comments', 'daily_llm_comments', 'read_limit', 'pass_max_actions'];

    public static function for(SocialAccount $account): array
    {
        return array_replace(self::DEFAULTS, array_intersect_key($account->agent_strategy['feed_engagement'] ?? [], self::DEFAULTS));
    }

    /** @return array<string, mixed> Réglages, plus un tirage et le nombre de combinaisons de chaque modèle. */
    public static function preview(SocialAccount $account): array
    {
        $settings = self::for($account);
        foreach (self::TEMPLATE_LISTS as $list) {
            $settings[$list.'_preview'] = array_map(fn (string $template) => [
                'template' => $template, 'sample' => Spintax::expand($template), 'combinations' => Spintax::combinations($template),
            ], $settings[$list]);
        }

        return $settings;
    }
}
