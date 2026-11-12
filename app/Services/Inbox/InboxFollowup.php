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

    public function allowsXBranchReply(int $branchLength, ?string $targetText, ?string $quote): bool
    {
        return $branchLength <= 2 || filled($quote) || $this->isQuestion($targetText);
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

    /**
     * Un simple merci sans question : rien à quoi répondre, un like suffit.
     * Le 12/11/2026, Wildy Caro répondait « Merci, c’est gentil 😊 » à tout, et TikTok retenait ces réponses.
     */
    public function isThanks(?string $content): bool
    {
        return ! $this->isQuestion($content) && (bool) preg_match('/^(?:(?:un )?(?:grand |gros )?merci(?: beaucoup| bcp| à toi| a toi| à vous| a vous| infiniment)?|mercii+|thanks?(?: a lot| so much)?|thank you(?: so much)?|thx|ty|obrigad[oa]|gracias|grazie|danke(?: schön)?|dank je wel|dankjewel|bedankt|спасибо)$/u', $this->words($content));
    }

    /** Emojis, autocollants (« [J'aime] ») ou GIF sans texte : l’utilisateur veut un emoji souriant en retour. */
    public function isEmojiOnly(?string $content): bool
    {
        return ! $this->isQuestion($content) && $this->words($content) === '';
    }

    public const SMILES = ['🙂', '😊', '😄'];

    private function words(?string $content): string
    {
        $text = preg_replace('/\[[^\]\n]{1,40}\]|@[\w.]+|[\p{So}\p{Sk}\p{Cs}\p{Mn}\x{FE0F}\x{200D}\x{20E3}\x{1F3FB}-\x{1F3FF}]/u', ' ', (string) $content);

        return trim(preg_replace('/[\s\p{P}]+/u', ' ', mb_strtolower($text)));
    }

    private function handle(string $value): string
    {
        return strtolower(ltrim(trim($value), '@'));
    }
}
