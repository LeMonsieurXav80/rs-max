<?php

namespace App\Services\Agent\Feed;

use App\Models\BrowserTask;
use App\Models\SocialAccount;
use App\Services\Agent\CommentFreshness;

/**
 * Threads ne marque pas la langue. Companion signale le lien « Translate » (texte dans une autre langue que
 * l'interface) et la langue de l'interface. Le 14/11/2026 sur le NAS, l'interface était en anglais et le lien
 * figurait sous presque toutes les publications françaises : il ne dit donc rien du français, sauf sous une
 * interface en français, où il signale une autre langue. `FrenchText` décide.
 */
final class ThreadsFeedNetwork implements FeedNetwork
{
    /** Limite de `ThreadsAgentController::submit`, en unités UTF-16. */
    public const COMMENT_LIMIT = 500;

    private const SENT = [BrowserTask::PENDING, BrowserTask::RUNNING, BrowserTask::DONE, BrowserTask::UNCERTAIN];

    public function validId(string $id): bool
    {
        return preg_match('/^[\w-]{5,20}$/', $id) === 1;
    }

    public function fresh(array $item): bool
    {
        return CommentFreshness::allowsPublishedAt($item['published_at'] ?? null);
    }

    public function language(array $item): string
    {
        $language = FrenchText::language((string) ($item['text'] ?? ''));

        return $language === 'fr' && ($item['ui_lang'] ?? null) === 'fr' && ($item['translatable'] ?? false) === true ? 'other' : $language;
    }

    public function fitsComment(string $text): bool
    {
        return trim($text) !== '' && strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2 <= self::COMMENT_LIMIT;
    }

    public function blockedLikeIds(SocialAccount $account, array $ids): array
    {
        return $this->blocked($account, 'like', $ids);
    }

    public function blockedCommentIds(SocialAccount $account, array $ids): array
    {
        return $this->blocked($account, 'comment', $ids);
    }

    public function recentlyCommentedAuthors(SocialAccount $account, int $days): array
    {
        return $this->gestures($account, 'comment')->where('created_at', '>=', now()->subDays($days))->get(['payload'])
            ->map(fn (BrowserTask $t) => strtolower((string) ($t->payload['target']['author'] ?? '')))
            ->filter()->flip()->map(fn () => true)->all();
    }

    /** Gestes Threads déjà demandés par Hermès ou par le script : un échec franc seul laisse réessayer. */
    private function blocked(SocialAccount $account, string $action, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $done = $this->gestures($account, $action)->whereIn('payload->target->id', $ids)->get(['payload'])
            ->map(fn (BrowserTask $t) => (string) ($t->payload['target']['id'] ?? ''))->all();

        return array_values(array_intersect($ids, $done));
    }

    private function gestures(SocialAccount $account, string $action)
    {
        return BrowserTask::where('social_account_id', $account->id)->where('kind', 'threads.agent.act')
            ->where('payload->action', $action)->whereIn('status', self::SENT);
    }
}
