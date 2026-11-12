<?php

namespace App\Services\Agent;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class CommentFreshness
{
    public const REJECTION = 'Cible de plus de 14 jours ou sans date vérifiable. Passer à la suivante.';

    public static function cutoff(?CarbonInterface $at = null): CarbonImmutable
    {
        return ($at ?? now())->toImmutable()->subRealDays(14);
    }

    public static function allows(?CarbonInterface $publishedAt, ?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $publishedAt && $publishedAt->betweenIncluded(self::cutoff($at), $at);
    }

    public static function allowsPublishedAt(mixed $date): bool
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $date)) {
            return false;
        }

        try {
            return self::allows(CarbonImmutable::parse($date));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function xStatusDate(string $id): ?CarbonImmutable
    {
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        // X encode le nombre de millisecondes depuis son époque dans les bits de poids fort.
        return CarbonImmutable::createFromTimestampMs(((int) $id >> 22) + 1288834974657, 'UTC');
    }

    public static function tikTokVideoDate(string $url): ?CarbonImmutable
    {
        if (! preg_match('~^https://www\.tiktok\.com/@[a-zA-Z0-9_.]+/video/([1-9][0-9]{18})$~D', $url, $match)) {
            return null;
        }
        $id = filter_var($match[1], FILTER_VALIDATE_INT);
        if ($id === false) {
            return null;
        }

        // Les 32 bits de poids fort des identifiants vidéo TikTok portent l'époque Unix.
        return CarbonImmutable::createFromTimestampUTC($id >> 32);
    }
}
