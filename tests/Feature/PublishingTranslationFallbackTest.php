<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Post;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Un texte deja redige dans la langue cible etait quand meme reecrit par l'IA
 * a la publication : le cache de traduction est indexe `{plateforme}_{langue}`
 * (`twitter_en`), alors que l'API, l'UI et le champ `content_en` ecrivent sous
 * la clé generique (`en`). Le lookup ratait donc systematiquement et relancait
 * gpt-4o-mini sur du contenu deja bon (cf. post 896).
 *
 * getTranslation() doit se replier sur la clé generique — sauf si la plateforme
 * a un contenu dedie, auquel cas la traduction generique porterait sur un autre
 * texte source et ne doit pas etre reutilisee.
 */
class PublishingTranslationFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $languages): SocialAccount
    {
        $platform = new Platform;
        $platform->id = 1;
        $platform->slug = 'twitter';

        $account = new SocialAccount;
        $account->id = 1;
        $account->languages = $languages;
        $account->setRelation('platform', $platform);

        return $account;
    }

    private function makePost(array $attributes): Post
    {
        return Post::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'content_fr' => 'Texte francais par defaut.',
            'status' => 'draft',
            'source_type' => 'manual',
        ], $attributes));
    }

    private function content(Post $post, SocialAccount $account): string
    {
        return app(PublishingService::class)->getContentForAccount($post, $account);
    }

    public function test_platform_scoped_key_wins(): void
    {
        Http::fake();

        $post = $this->makePost(['translations' => ['twitter_en' => 'Tweet-specific English.', 'en' => 'Generic English.']]);

        $this->assertSame('Tweet-specific English.', $this->content($post, $this->account(['en'])));
        Http::assertNothingSent();
    }

    public function test_generic_lang_key_is_used_instead_of_retranslating(): void
    {
        Http::fake();

        $post = $this->makePost(['translations' => ['en' => 'My own English wording.']]);

        $this->assertSame('My own English wording.', $this->content($post, $this->account(['en'])));
        Http::assertNothingSent();
    }

    public function test_content_en_is_used_even_when_publishing_to_a_platform(): void
    {
        Http::fake();

        $post = $this->makePost(['content_en' => 'English from the content_en field.']);

        $this->assertSame('English from the content_en field.', $this->content($post, $this->account(['en'])));
        Http::assertNothingSent();
    }

    public function test_bilingual_account_pairs_the_generic_translation_with_the_french(): void
    {
        Http::fake();

        $post = $this->makePost(['translations' => ['en' => 'My own English wording.']]);

        $this->assertSame(
            "🇫🇷 Texte francais par defaut.\n\n🇬🇧 My own English wording.",
            $this->content($post, $this->account(['fr', 'en'])),
        );
        Http::assertNothingSent();
    }

    public function test_generic_translation_is_ignored_when_the_platform_has_dedicated_content(): void
    {
        Setting::setEncrypted('openai_api_key', 'sk-test');
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Translation of the tweet.']]]]),
        ]);

        $post = $this->makePost([
            'platform_contents' => ['twitter' => 'Version courte pour Twitter.'],
            'translations' => ['en' => 'Generic English, translated from the default text.'],
        ]);

        // La traduction generique porte sur `content_fr`, pas sur la version Twitter :
        // la reutiliser publierait le mauvais texte. On traduit donc la version dediee.
        $this->assertSame('Translation of the tweet.', $this->content($post, $this->account(['en'])));

        Http::assertSent(fn ($request) => str_contains(
            $request->data()['messages'][1]['content'] ?? '',
            'Version courte pour Twitter.',
        ));

        $this->assertSame('Translation of the tweet.', $post->fresh()->translations['twitter_en']);
    }
}
