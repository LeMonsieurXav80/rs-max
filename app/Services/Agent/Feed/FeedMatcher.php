<?php

namespace App\Services\Agent\Feed;

use Illuminate\Support\Str;

/**
 * Compare une publication du fil à la fiche Community Manager, sans modèle de langue.
 * Mots entiers, insensibles à la casse et aux accents ; « squats » répond à « squat »,
 * « pull-up », « pull up » et « pullup » se valent. Une exclusion l'emporte sur tout.
 */
final class FeedMatcher
{
    /** @var list<array{phrase:string, pattern:string}> */
    private array $keywords = [];

    /** @var list<array{phrase:string, pattern:string}> */
    private array $exclusions = [];

    /** @var array<string, true> */
    private array $targets = [];

    /** @var array<string, true> */
    private array $excludedHandles = [];

    public function __construct(array $strategy)
    {
        foreach ([...($strategy['search_queries'] ?? []), ...($strategy['hashtags'] ?? [])] as $value) {
            if (is_string($value) && ($entry = self::entry($value))) {
                $this->keywords[] = $entry;
            }
        }
        foreach ($strategy['exclusions'] ?? [] as $value) {
            if (! is_string($value)) {
                continue;
            }
            if ($handle = self::handle($value)) {
                $this->excludedHandles[$handle] = true;
            } elseif ($entry = self::entry($value)) {
                $this->exclusions[] = $entry;
            }
        }
        foreach ($strategy['target_accounts'] ?? [] as $value) {
            if (is_string($value) && ($handle = self::handle($value))) {
                $this->targets[$handle] = true;
            }
        }
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(Str::ascii(str_replace(['’', '‘'], "'", $text)));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /** Premier mot ou compte exclu qui écarte la publication, ou `null`. */
    public function excludedBy(string $text, string $author): ?string
    {
        if (isset($this->excludedHandles[strtolower(ltrim($author, '@'))])) {
            return '@'.strtolower(ltrim($author, '@'));
        }
        $normalized = self::normalize($text);
        foreach ($this->exclusions as $entry) {
            if (preg_match($entry['pattern'], $normalized)) {
                return $entry['phrase'];
            }
        }

        return null;
    }

    /** @return list<string> Mots-clés de la fiche trouvés dans le texte. */
    public function keywordHits(string $text): array
    {
        $normalized = self::normalize($text);

        return array_values(array_unique(array_map(fn ($entry) => $entry['phrase'],
            array_filter($this->keywords, fn ($entry) => preg_match($entry['pattern'], $normalized)))));
    }

    public function isTarget(string $author): bool
    {
        return isset($this->targets[strtolower(ltrim($author, '@'))]);
    }

    /** @return array{phrase:string, pattern:string}|null */
    private static function entry(string $value): ?array
    {
        $phrase = self::normalize(ltrim(trim($value), '#'));
        if ($phrase === '') {
            return null;
        }
        $words = explode(' ', $phrase);
        // Pluriel sur le dernier mot ; mots composés acceptés avec espace, tiret (déjà normalisé) ou collés.
        $spaced = implode(' ', array_map('preg_quote', $words));
        $glued = preg_quote(implode('', $words));
        $alternatives = count($words) > 1 ? '(?:'.$spaced.'|'.$glued.')' : $spaced;

        return ['phrase' => trim($value), 'pattern' => '/(?<![a-z0-9])'.$alternatives.'(?:s|x|es)?(?![a-z0-9])/'];
    }

    private static function handle(string $value): ?string
    {
        // Un compte s'écrit « @pseudo » ou en lien de profil : un mot nu reste un mot.
        return preg_match('~^(?:https://(?:www\.)?(?:x|twitter|threads)\.(?:com|net)/@?|@)([A-Za-z0-9_.]{1,30})/?$~', trim($value), $m)
            ? strtolower($m[1]) : null;
    }
}
