<?php

namespace App\Services;

use App\Jobs\ResharePlatformJob;
use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Adapters\ResharingAdapterInterface;
use Illuminate\Support\Facades\DB;

class ResharingService
{
    public function __construct(
        private ExternalUrlResolverService $urlResolver,
    ) {}

    public const MODE_AUTO = 'auto';

    public const MODE_NATIVE_REPOST = 'native_repost';

    public const MODE_NATIVE_QUOTE = 'native_quote';

    public const MODE_LINK = 'link';

    /**
     * Repartage une publication interne (Post existant) vers un ou plusieurs comptes.
     *
     * @param  Post  $sourcePost  Le post déjà publié à repartager.
     * @param  int[]  $targetAccountIds  IDs des SocialAccount cibles.
     * @param  string  $mode  auto | native_repost | native_quote | link
     * @param  string|null  $text  Texte d'accompagnement (requis pour native_quote et link).
     * @return Post Le nouveau Post shadow créé.
     */
    public function reshareFromPost(User $user, Post $sourcePost, array $targetAccountIds, string $mode = self::MODE_AUTO, ?string $text = null): Post
    {
        $accounts = SocialAccount::with('platform')
            ->whereIn('id', $targetAccountIds)
            ->get();

        if ($accounts->isEmpty()) {
            throw new \InvalidArgumentException('Aucun compte cible valide.');
        }

        return DB::transaction(function () use ($user, $sourcePost, $accounts, $mode, $text) {
            $sourceUrl = $this->primarySourceUrl($sourcePost);

            $post = Post::create([
                'user_id' => $user->id,
                'content_fr' => $this->buildContentFr($mode, $text),
                'link_url' => $mode === self::MODE_LINK ? $sourceUrl : null,
                'status' => 'publishing',
                'source_type' => 'reshare',
                'reshare_source_post_platform_id' => null,
                'reshare_source_url' => $sourceUrl,
                'reshare_mode' => $mode,
                'published_at' => null,
            ]);

            foreach ($accounts as $account) {
                $resolved = $this->resolveSourceForAccount($sourcePost, $account);
                $effectiveMode = $this->resolveEffectiveMode($mode, $account, $resolved !== null, ! empty($text));

                $postPlatform = PostPlatform::create([
                    'post_id' => $post->id,
                    'social_account_id' => $account->id,
                    'platform_id' => $account->platform_id,
                    'status' => $effectiveMode === null ? 'failed' : 'publishing',
                    'error_message' => $effectiveMode === null ? "Mode '{$mode}' non supporté pour la plateforme {$account->platform->slug}." : null,
                ]);

                if ($effectiveMode === null) {
                    continue;
                }

                ResharePlatformJob::dispatch(
                    $postPlatform->id,
                    $effectiveMode,
                    $resolved['external_id'] ?? null,
                    $resolved['url'] ?? $post->reshare_source_url,
                );
            }

            // Pointer de traçabilité globale : le premier PostPlatform source compatible.
            $firstSource = $this->firstCompatibleSource($sourcePost, $accounts);
            if ($firstSource) {
                $post->update(['reshare_source_post_platform_id' => $firstSource->id]);
            }

            return $post->fresh('postPlatforms');
        });
    }

    /**
     * Repartage à partir d'une URL externe collée (cas du tweet/post pas géré dans rs-max).
     *
     * @param  int[]  $targetAccountIds
     */
    public function reshareFromUrl(User $user, string $sourceUrl, array $targetAccountIds, string $mode = self::MODE_AUTO, ?string $text = null): Post
    {
        $resolved = $this->urlResolver->resolve($sourceUrl);
        if (! $resolved) {
            throw new \InvalidArgumentException('URL non reconnue ou plateforme non supportée.');
        }

        $platform = Platform::where('slug', $resolved['platform'])->first();

        $accounts = SocialAccount::with('platform')
            ->whereIn('id', $targetAccountIds)
            ->get();

        if ($accounts->isEmpty()) {
            throw new \InvalidArgumentException('Aucun compte cible valide.');
        }

        return DB::transaction(function () use ($user, $resolved, $platform, $accounts, $mode, $text) {
            $post = Post::create([
                'user_id' => $user->id,
                'content_fr' => $this->buildContentFr($mode, $text),
                'link_url' => $mode === self::MODE_LINK ? $resolved['url'] : null,
                'status' => 'publishing',
                'source_type' => 'reshare',
                'reshare_source_platform_id' => $platform?->id,
                'reshare_source_external_id' => $resolved['external_id'],
                'reshare_source_url' => $resolved['url'],
                'reshare_mode' => $mode,
            ]);

            foreach ($accounts as $account) {
                $samePlatform = $account->platform_id === $platform?->id;
                $effectiveMode = $this->resolveEffectiveMode($mode, $account, $samePlatform && $resolved['supports_native'], ! empty($text));

                $postPlatform = PostPlatform::create([
                    'post_id' => $post->id,
                    'social_account_id' => $account->id,
                    'platform_id' => $account->platform_id,
                    'status' => $effectiveMode === null ? 'failed' : 'publishing',
                    'error_message' => $effectiveMode === null
                        ? "Mode '{$mode}' non supporté pour la plateforme {$account->platform->slug}."
                        : null,
                ]);

                if ($effectiveMode === null) {
                    continue;
                }

                ResharePlatformJob::dispatch(
                    $postPlatform->id,
                    $effectiveMode,
                    $samePlatform ? $resolved['external_id'] : null,
                    $resolved['url'],
                );
            }

            return $post->fresh('postPlatforms');
        });
    }

    /**
     * Détermine le mode effectif pour un compte cible.
     * Retourne null si le mode demandé est incompatible avec ce compte.
     */
    private function resolveEffectiveMode(string $requested, SocialAccount $account, bool $sourceCompatible, bool $hasText): ?string
    {
        $supportsNative = $this->adapterSupportsNative($account);

        if ($requested === self::MODE_LINK) {
            return self::MODE_LINK;
        }

        if ($requested === self::MODE_NATIVE_REPOST) {
            return ($supportsNative && $sourceCompatible) ? self::MODE_NATIVE_REPOST : null;
        }

        if ($requested === self::MODE_NATIVE_QUOTE) {
            return ($supportsNative && $sourceCompatible && $hasText) ? self::MODE_NATIVE_QUOTE : null;
        }

        // auto : on prend le meilleur disponible
        if ($supportsNative && $sourceCompatible) {
            return $hasText ? self::MODE_NATIVE_QUOTE : self::MODE_NATIVE_REPOST;
        }

        return self::MODE_LINK;
    }

    private function adapterSupportsNative(SocialAccount $account): bool
    {
        $adapter = \App\Services\Adapters\AdapterFactory::make($account->platform->slug ?? '');

        return $adapter instanceof ResharingAdapterInterface;
    }

    private function buildContentFr(string $mode, ?string $text): string
    {
        if ($mode === self::MODE_NATIVE_REPOST) {
            return ''; // retweet pur, pas de texte
        }

        return $text ?? '';
    }

    /**
     * Pour un Post source, trouve le PostPlatform à utiliser pour repartager vers un compte cible donné.
     * Retourne null si aucun PostPlatform compatible (= même plateforme).
     *
     * @return array{external_id: string, url: ?string}|null
     */
    private function resolveSourceForAccount(Post $sourcePost, SocialAccount $targetAccount): ?array
    {
        $sourcePlatform = $sourcePost->postPlatforms()
            ->where('platform_id', $targetAccount->platform_id)
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->first();

        if (! $sourcePlatform) {
            return null;
        }

        return [
            'external_id' => $sourcePlatform->external_id,
            'url' => $sourcePlatform->platform_url,
        ];
    }

    private function firstCompatibleSource(Post $sourcePost, $accounts): ?PostPlatform
    {
        foreach ($accounts as $account) {
            $pp = $sourcePost->postPlatforms()
                ->where('platform_id', $account->platform_id)
                ->where('status', 'published')
                ->whereNotNull('external_id')
                ->first();
            if ($pp) {
                return $pp;
            }
        }

        return null;
    }

    private function primarySourceUrl(Post $sourcePost): ?string
    {
        $pp = $sourcePost->postPlatforms()
            ->where('status', 'published')
            ->whereNotNull('platform_url')
            ->first();

        return $pp?->platform_url;
    }
}
