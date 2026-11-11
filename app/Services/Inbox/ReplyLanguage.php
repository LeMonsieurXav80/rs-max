<?php

namespace App\Services\Inbox;

use App\Models\SocialAccount;

class ReplyLanguage
{
    public const LABELS = [
        'fr' => 'français',
        'en' => 'anglais',
        'pt' => 'portugais',
        'es' => 'espagnol',
        'de' => 'allemand',
        'it' => 'italien',
    ];

    public static function code(SocialAccount $account): string
    {
        $selected = $account->agent_strategy['reply_language'] ?? null;
        if (is_string($selected) && isset(self::LABELS[$selected])) {
            return $selected;
        }

        foreach ($account->languages ?? [] as $language) {
            if (is_string($language) && isset(self::LABELS[$language])) {
                return $language;
            }
        }

        return 'fr';
    }

    public static function instruction(SocialAccount $account): string
    {
        return 'Rédige en '.self::LABELS[self::code($account)].', la langue de réponse du compte, même si le message, la discussion ou le persona sont dans une autre langue.';
    }
}
