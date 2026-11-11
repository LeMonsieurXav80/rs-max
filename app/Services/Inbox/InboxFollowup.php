<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\SocialAccount;

class InboxFollowup
{
    public function isOwn(InboxItem $item, SocialAccount $account): bool
    {
        if (filled($item->author_external_id) && (string) $item->author_external_id === (string) $account->platform_account_id) {
            return true;
        }

        if (! $item->author_username) {
            return false;
        }

        $author = $this->handle($item->author_username);

        return in_array($author, array_map($this->handle(...), array_filter([
            $account->browser_username, $account->name, $account->credentials['username'] ?? null,
        ])), true);
    }

    public function isNestedReply(InboxItem $item): bool
    {
        return filled($item->parent_id) && $item->type !== 'dm';
    }

    public function verifiedXQuote(array $target, array $last): ?string
    {
        $quote = $target['quote'] ?? null;
        if (! is_string($quote) || blank($quote) || ! isset($target['id'], $target['author'])
            || ($last['id'] ?? null) !== $target['id'] || ($last['author'] ?? null) !== $target['author']
            || ($last['quote'] ?? null) !== $quote) {
            return null;
        }

        return $quote;
    }

    public function isQuestion(?string $content): bool
    {
        $text = preg_replace('~https?://\S+~i', '', trim((string) $content));
        if (preg_match('/[?？؟]/u', $text)) {
            return true;
        }

        return (bool) preg_match('~(?:^|[.!\n]\s*)(?:@[\w.]+\s+)*(?:'
            .'est[- ]ce que|qu[’\x27]est[- ]ce que|c[’\x27]est quoi|où (?:trouver|acheter|est|as[- ]tu|avez[- ]vous)|'
            .'comment (?:faire|trouver|acheter|devenir|tu|vous|on|ça|puis[- ]je)|pourquoi (?:tu|vous|on|ça|ce|il|elle)|'
            .'combien (?:ça|coûte|de|tu|vous)|quel(?:le|s|les)? (?:est|sont|taille|marque|modèle|prix|couleur|référence)|'
            .'tu (?:peux|pourrais|sais)|vous (?:pouvez|pourriez|savez)|possible de|'
            .'how (?:do|did|can|could|much|many|would|long)|where (?:do|did|can|could|is|are)|'
            .'what (?:is|are|do|did|would)|can (?:i|you|we)|could (?:i|you|we)|any (?:tips|advice)'
            .')\b~iu', $text);
    }

    private function handle(string $value): string
    {
        return strtolower(ltrim(trim($value), '@'));
    }
}
