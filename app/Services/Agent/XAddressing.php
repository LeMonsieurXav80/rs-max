<?php

namespace App\Services\Agent;

/**
 * Place du compte dans un fil X avant toute réponse, quel que soit le chemin (Hermès, mentions, Companion local).
 * Le 12/11/2026, Wildy Caro a répondu « Merci beaucoup, c’est vraiment gentil ! » au compliment qu’une personne
 * adressait à une autre : le rédacteur présentait toujours la cible comme un message reçu par le compte.
 */
class XAddressing
{
    public const ADDRESSED = 'interpelle';

    public const PARTICIPANT = 'participant';

    public const OUTSIDER = 'exterieur';

    /**
     * @param  array<int, array{id?: string, author?: string, text?: ?string}>  $conversation  branche lue, de l’origine à la cible
     * @param  array{id?: string, author?: string, text?: ?string}|null  $target  cible, si la branche ne la contient pas en dernier
     * @return array{compte: string, role: string, auteur_cible: string, repond_a: ?string, consigne: string}
     */
    public static function describe(string $username, array $conversation, ?array $target = null): array
    {
        $own = strtolower(ltrim($username, '@'));
        $branch = array_values($conversation);
        $last = $branch[array_key_last($branch) ?? 0] ?? null;
        $target ??= $last ?? [];
        $handle = fn ($post) => strtolower(ltrim((string) ($post['author'] ?? ''), '@'));
        $author = $handle($target);
        if ($last !== null && (isset($last['id'], $target['id']) ? $last['id'] === $target['id']
            : $handle($last) === $author && ($last['text'] ?? null) === ($target['text'] ?? null))) {
            array_pop($branch);
        }
        // Une personne qui prolonge son propre message répond encore à celui qu’elle visait au-dessus.
        $above = $branch;
        while ($above && $handle($above[array_key_last($above)]) === $author) {
            array_pop($above);
        }
        $parent = $above[array_key_last($above) ?? 0] ?? null;
        $parentAuthor = $parent ? $handle($parent) : null;
        $named = $own !== '' && preg_match('/(?<![A-Za-z0-9_])@'.preg_quote($own, '/').'(?![A-Za-z0-9_])/i', (string) ($target['text'] ?? ''));

        $role = match (true) {
            $own !== '' && ($parentAuthor === $own || $named) => self::ADDRESSED,
            $own !== '' && collect($branch)->contains(fn ($p) => $handle($p) === $own) => self::PARTICIPANT,
            default => self::OUTSIDER,
        };
        $to = $parentAuthor ? '@'.$parentAuthor : null;

        return [
            'compte' => '@'.$own,
            'role' => $role,
            'auteur_cible' => '@'.$author,
            'repond_a' => $to,
            'consigne' => match ($role) {
                self::ADDRESSED => "Le message de @{$author} s’adresse à @{$own} : réponds-lui directement.",
                self::PARTICIPANT => "@{$own} a participé plus haut, mais ce message de @{$author} répond à {$to}, pas à toi. "
                    .'Ne réponds pas comme s’il t’était adressé : pas de remerciement, ni de réponse à une question ou un compliment destinés à quelqu’un d’autre. '
                    .'Interviens seulement avec un apport utile sur le sujet.',
                default => "@{$own} n’apparaît pas dans ce fil : tu interviens comme un tiers dans la conversation de @{$author}"
                    .($to && $to !== '@'.$author ? " avec {$to}" : '').'. Rien ne t’y est adressé : ne remercie pas, '
                    .'ne réponds pas à un compliment, une question ou une demande comme s’ils te visaient, et ne parle pas de « ta » publication. '
                    .'Commente le sujet avec un apport utile, en t’adressant à son auteur.',
            },
        ];
    }

    /** Un remerciement n’a de sens que si le compte est interpellé. */
    public static function fits(?string $role, string $reply): bool
    {
        if ($role === null || $role === self::ADDRESSED) {
            return true;
        }

        return ! preg_match('/^\W*(?:merci|thanks|thank you|thx|obrigad[oa]|gracias|grazie|danke)(?![\p{L}])/iu', trim($reply));
    }

    public const REJECTION = 'Réponse écrite comme si la cible s’adressait au compte, alors qu’elle vise quelqu’un d’autre. Reformule en tiers ou passe au suivant.';
}
