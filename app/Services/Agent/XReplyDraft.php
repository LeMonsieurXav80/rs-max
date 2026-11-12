<?php

namespace App\Services\Agent;

use App\Models\InboxItem;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxReplyGenerator;

class XReplyDraft
{
    public const LIMIT = 280;

    public function __construct(private InboxReplyGenerator $generator) {}

    public static function fits(string $text, int $limit = self::LIMIT): bool
    {
        // Borne prudente selon la pondération v3 de twitter-text. Les URL
        // et les séquences d’emojis sont comptées sans réduction.
        $doubleWeight = preg_match_all('/[^\x{0000}-\x{10FF}\x{2000}-\x{200D}\x{2010}-\x{201F}\x{2032}-\x{2037}]/u', $text);

        return $text !== '' && $doubleWeight !== false && mb_strlen($text) + $doubleWeight <= $limit;
    }

    /**
     * Le jugement « pertinent » du modèle ne suffit pas : il doit nommer un terme de la stratégie du compte.
     * Le 12/11/2026, Wildy Caro (mode) a répondu à deux annonces de launcher pour joueurs.
     */
    public static function matchesStrategy(SocialAccount $account, mixed $subject): bool
    {
        $strategy = $account->agent_strategy ?? [];
        $terms = collect([...($strategy['hashtags'] ?? []), ...($strategy['search_queries'] ?? []), ...($strategy['editorial_topics'] ?? [])])
            ->filter(fn ($t) => is_string($t))->map(fn ($t) => self::normalize($t))->filter()->values();
        if ($terms->isEmpty()) {
            return true;
        }
        $subject = is_string($subject) ? self::normalize($subject) : '';

        return mb_strlen($subject) >= 3 && $terms->contains(fn ($t) => $t === $subject || str_contains($subject, $t) || str_contains($t, $subject));
    }

    private static function normalize(string $text): string
    {
        $ascii = \Illuminate\Support\Str::ascii(mb_strtolower(ltrim(trim($text), '#')));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/\blang:[a-z]{2}\b/', '', $ascii)));
    }

    public function generate(InboxItem $item, SocialAccount $account, string $context, string $contextLabel, ?string $instructions = null, bool $filterByStrategy = false): string
    {
        foreach ([220, 170] as $index => $target) {
            $style = "Vise {$target} caractères au maximum, dans une phrase complète. Certains caractères et emojis comptent double sur X.";
            if ($index > 0) {
                $style = 'Le premier brouillon dépassait la limite. Reformule en conservant seulement le point essentiel. '.$style;
            }
            $reply = $this->generator->generate($item, $account, true, self::LIMIT, $context, 'X', $contextLabel,
                extraInstructions: trim($instructions."\n".$style), jsonOutput: $filterByStrategy);
            abort_unless(filled($reply), 503, 'Brouillon indisponible.');

            if ($filterByStrategy) {
                $draft = json_decode($reply, true);
                $text = $draft['texte'] ?? null;
                abort_unless(($draft['pertinent'] ?? false) === true && is_string($text) && filled($text)
                    && self::matchesStrategy($account, $draft['sujet'] ?? null),
                    422, 'Publication hors ciblage ou contexte insuffisant. Passer à la suivante.');
                $reply = $text;
            }
            $reply = trim($reply);
            if (self::fits($reply)) {
                return $reply;
            }
        }

        abort(422, 'Brouillon encore trop long après reformulation.');
    }
}
