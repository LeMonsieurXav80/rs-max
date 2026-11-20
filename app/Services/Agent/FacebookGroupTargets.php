<?php

namespace App\Services\Agent;

/** Liens de groupes explicitement choisis ; aucune découverte ni adhésion automatique. */
class FacebookGroupTargets
{
    public static function canonical(string $value): ?string
    {
        $url = parse_url(trim($value));
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https'
            || ! in_array(strtolower($url['host'] ?? ''), ['facebook.com', 'www.facebook.com', 'web.facebook.com', 'm.facebook.com'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['port'])
            || ! preg_match('~^/groups/([a-zA-Z0-9][a-zA-Z0-9._-]{0,199})/?$~D', $url['path'] ?? '', $match)) {
            return null;
        }

        return 'https://www.facebook.com/groups/'.strtolower($match[1]).'/';
    }
}
