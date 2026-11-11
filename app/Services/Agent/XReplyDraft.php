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
                abort_unless(($draft['pertinent'] ?? false) === true && is_string($text) && filled($text),
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
