<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Le bandeau « réponse(s) en échec » ne montre que les vrais échecs, pas les commentaires classés. */
class InboxFailedBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_commentaire_classe_apres_trois_tentatives_nest_plus_en_echec(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $a = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'name' => 'Compte', 'driver' => 'browser']);
        $a->users()->attach($user, ['is_active' => true]);
        $base = ['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'type' => 'comment', 'content' => 'Bravo',
            'reply_content' => 'Merci', 'reply_attempts' => 3];
        InboxItem::create($base + ['external_id' => 'echec', 'status' => 'reply_failed']);
        InboxItem::create($base + ['external_id' => 'classe', 'status' => 'ignored', 'agent_review_reason' => 'reconciled_absent']);
        InboxItem::create($base + ['external_id' => 'epuise', 'status' => 'read']);

        $this->actingAs($user)->getJson(route('inbox.scheduledStatus', ['accounts' => [$a->id]]))
            ->assertOk()->assertJsonPath('failed', 2);
    }
}
