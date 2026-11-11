<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Persona;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityManagerInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_ancien_commentaire_tiktok_introuvable_est_differe_sans_tentative(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-09T10:00:00Z')->setTimezone(config('app.timezone')));
        $user = User::factory()->create(['role' => 'manager']);
        $persona = Persona::create(['name' => 'Persona', 'system_prompt' => 'Réponse brève', 'is_active' => true]);
        $account = SocialAccount::create(['name' => 'Compte TikTok', 'platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id,
            'persona_id' => $persona->id, 'driver' => 'browser', 'browser_profile' => 'nas-test', 'browser_username' => 'owner']);
        $account->users()->attach($user, ['is_active' => true]);
        Sanctum::actingAs($user);
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id,
            'type' => 'comment', 'external_id' => 'old-comment', 'author_username' => 'visitor', 'content' => 'Bonjour',
            'post_content' => 'Légende', 'status' => 'unread', 'posted_at' => now()->subDays(3)]);

        $this->mock(\App\Services\Browser\BrowserTaskQueue::class)->shouldReceive('run')->once()
            ->withArgs(fn ($a, $kind, $payload) => $a->is($account) && $kind === 'tiktok.target'
                && $payload['target'] === ['author' => 'visitor', 'content' => 'Bonjour', 'caption' => 'Légende'])
            ->andReturn(['target_found' => false]);

        $this->getJson('/api/community-manager/inbox/'.$item->id.'/context')
            ->assertConflict()->assertJsonPath('skip_item', true)->assertJsonPath('review_reason', 'tiktok_target_not_found');
        $item->refresh();
        $this->assertSame(360, (int) $item->agent_reviewed_at->diffInMinutes($item->agent_retry_at));
        $this->getJson('/api/community-manager/accounts/'.$account->id.'/inbox?agent_queue=1')
            ->assertOk()->assertJsonPath('pagination.total', 0);
        $this->assertSame(0, (int) $item->fresh()->reply_attempts);
        $this->assertNull($item->fresh()->reply_scheduled_at);
    }

    public function test_contexte_persona_scope_aperçu_puis_planification_sans_appel_ia(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SendAutonomousInboxReply::class]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07T12:00:00Z')->setTimezone(config('app.timezone')));
        Http::preventStrayRequests();
        $u = User::factory()->create(['role' => 'manager']);
        $p = Persona::create(['name' => 'Persona associé', 'system_prompt' => 'Ton chaleureux et sobre', 'is_active' => true]);
        $a = SocialAccount::create(['name' => 'Compte attribué', 'platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'persona_id' => $p->id, 'driver' => 'browser']);
        $a->users()->attach($u, ['is_active' => true]);
        Sanctum::actingAs($u);
        $i = InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'type' => 'comment', 'external_id' => 'comment-1', 'content' => 'Très jolie paire !', 'post_content' => 'Nouvelle vidéo', 'status' => 'unread', 'posted_at' => now()]);
        $this->getJson('/api/community-manager/accounts/'.$a->id.'/inbox')->assertOk()->assertJsonPath('items.0.post_content', 'Nouvelle vidéo');
        $ctx = $this->getJson('/api/community-manager/inbox/'.$i->id.'/context')->assertOk()->assertJsonPath('persona.instructions', 'Ton chaleureux et sobre')->json('context_id');
        $data = ['context_id' => $ctx, 'reply_text' => 'Merci beaucoup 🖤'];
        $url = '/api/community-manager/inbox/'.$i->id.'/reply';
        $this->postJson($url, $data)->assertOk()->assertJsonPath('dry_run', true);
        $this->assertNull($i->fresh()->reply_scheduled_at);
        $p->update(['system_prompt' => 'Nouveau ton']);
        $this->postJson($url, $data + ['dry_run' => false])->assertConflict();
        $data['context_id'] = $this->getJson('/api/community-manager/inbox/'.$i->id.'/context')->json('context_id');
        $this->postJson($url, $data + ['dry_run' => false])->assertAccepted()->assertJsonPath('validation_required', false)->assertJsonPath('queued', true);
        $this->assertNotNull($i->fresh()->reply_activity_window);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendAutonomousInboxReply::class);
        $this->assertSame('Merci beaucoup 🖤', $i->fresh()->reply_content);
        $this->assertNull($i->fresh()->reply_draft);
        Http::assertNothingSent();
        $a->users()->detach($u);
        $this->getJson('/api/community-manager/inbox/'.$i->id.'/context')->assertNotFound();
    }

    public function test_budget_api_bloque_avant_envoi(): void
    {
        $u = User::factory()->create();
        $a = SocialAccount::create(['name' => 'Compte API', 'platform_id' => Platform::firstOrCreate(['slug' => 'facebook'], ['name' => 'Facebook', 'auth_type' => 'oauth2'])->id, 'agent_hourly_limit' => 0]);
        $a->users()->attach($u, ['is_active' => true]);
        Sanctum::actingAs($u);
        $i = InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'type' => 'comment', 'external_id' => 'comment-2', 'content' => 'Bonjour', 'status' => 'unread']);
        $network = \Mockery::mock(\App\Services\Inbox\PlatformInboxInterface::class);
        $network->shouldNotReceive('sendReply');
        $this->mock(\App\Services\Inbox\InboxSyncService::class)->shouldReceive('getServiceForAccount')->andReturn($network);
        $this->postJson('/api/inbox/'.$i->id.'/reply', ['reply_text' => 'Bonjour !', 'dry_run' => false])->assertStatus(429);
        $this->assertNull($i->fresh()->replied_at);
    }

    public function test_le_contexte_x_contient_la_publication_origine_et_refuse_un_fil_deja_traite(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SendAutonomousInboxReply::class]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07T12:00:00Z')->setTimezone(config('app.timezone')));
        Http::preventStrayRequests();
        $u = User::factory()->create(['role' => 'manager']);
        $p = Persona::create(['name' => 'Voix associée', 'system_prompt' => 'Chaleureux', 'is_active' => true]);
        $a = SocialAccount::create(['name' => 'Compte attribué', 'platform_id' => Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X', 'auth_type' => 'oauth2'])->id,
            'driver' => 'browser', 'browser_username' => 'owner', 'browser_profile' => 'nas-test', 'persona_id' => $p->id]);
        $a->users()->attach($u, ['is_active' => true]);
        Sanctum::actingAs($u);
        $i = InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'type' => 'comment', 'external_id' => '987',
            'author_username' => 'visitor', 'content' => 'Très jolie tenue', 'status' => 'unread', 'posted_at' => now()->subMinute()]);
        $data = ['target' => ['id' => '987', 'author' => 'visitor', 'text' => 'Très jolie tenue'], 'context_complete' => true,
            'conversation' => [['author' => 'owner', 'text' => 'La robe du jour'], ['author' => 'visitor', 'text' => 'Très jolie tenue']], 'visible_replies' => []];
        $queue = $this->mock(\App\Services\Browser\BrowserTaskQueue::class);
        $queue->shouldReceive('run')->twice()->withArgs(fn ($account, $kind, $payload) => $account->id === $a->id && $kind === 'x.agent.read' && $payload['target_id'] === '987')
            ->andReturn($data, array_replace($data, ['visible_replies' => [['author' => 'owner', 'text' => 'Merci !']]]));
        $url = '/api/community-manager/inbox/'.$i->id.'/context';
        $context = $this->getJson($url)->assertOk()->assertJsonPath('conversation.0.text', 'La robe du jour')->assertJsonPath('persona.instructions', 'Chaleureux')->json('context_id');
        $this->postJson('/api/community-manager/inbox/'.$i->id.'/reply', ['context_id' => $context, 'reply_text' => 'Merci à vous !', 'dry_run' => false])->assertAccepted();
        $this->assertNotNull($i->fresh()->reply_scheduled_at);
        $this->assertSame(0, (int) $i->fresh()->reply_attempts);
        $this->assertSame(0, \App\Models\AccountActionEvent::count());
        $this->getJson($url)->assertConflict();
        Http::assertNothingSent();
    }

    public function test_x_agent_queue_defers_a_missing_target_without_reopening_its_thread(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-10T10:00:00Z')->setTimezone(config('app.timezone')));
        $user = User::factory()->create(['role' => 'manager']);
        $persona = Persona::create(['name' => 'Persona', 'system_prompt' => 'Sobre', 'is_active' => true]);
        $account = SocialAccount::create([
            'name' => 'Compte X', 'platform_id' => Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X'])->id,
            'driver' => 'browser', 'browser_profile' => 'nas-test', 'browser_username' => 'owner', 'persona_id' => $persona->id,
        ]);
        $account->users()->attach($user, ['is_active' => true]);
        Sanctum::actingAs($user);
        $common = ['social_account_id' => $account->id, 'platform_id' => $account->platform_id,
            'type' => 'comment', 'author_username' => 'visitor', 'content' => 'Bonjour', 'status' => 'unread'];
        $item = InboxItem::create($common + ['external_id' => '987', 'posted_at' => now()->subDay()]);
        $inbox = '/api/community-manager/accounts/'.$account->id.'/inbox';
        $this->getJson($inbox.'?agent_queue=1')->assertOk()->assertJsonPath('pagination.total', 1);

        $this->mock(\App\Services\Browser\BrowserTaskQueue::class)->shouldReceive('run')->once()
            ->andThrow(new \App\Services\Browser\BrowserException('Mention absente du fil.'));
        $context = '/api/community-manager/inbox/'.$item->id.'/context';
        $this->getJson($context)->assertConflict()->assertJsonPath('review_reason', 'x_target_not_found');
        $this->getJson($context)->assertConflict()->assertJsonPath('skip_item', true);
        $this->getJson($inbox.'?agent_queue=1')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson($inbox)->assertOk()->assertJsonPath('pagination.total', 1);
        $item->refresh();
        $this->assertSame(360, (int) $item->agent_reviewed_at->diffInMinutes($item->agent_retry_at));
        $this->assertSame(0, (int) $item->reply_attempts);
    }

    public function test_x_retire_un_compte_suspendu_et_ne_relit_pas_la_cible(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-09T12:00:00Z')->setTimezone(config('app.timezone')));
        $user = User::factory()->create(['role' => 'manager']);
        $persona = Persona::create(['name' => 'Persona', 'system_prompt' => 'Sobre', 'is_active' => true]);
        $account = SocialAccount::create([
            'name' => 'Compte X', 'platform_id' => Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X'])->id,
            'driver' => 'browser', 'browser_profile' => 'nas-test', 'browser_username' => 'owner', 'persona_id' => $persona->id,
        ]);
        $account->users()->attach($user, ['is_active' => true]);
        Sanctum::actingAs($user);
        $item = InboxItem::create(['social_account_id' => $account->id, 'platform_id' => $account->platform_id,
            'type' => 'comment', 'external_id' => '987', 'author_username' => 'visitor', 'content' => 'Bonjour', 'status' => 'unread', 'posted_at' => now()->subMinute()]);
        $this->mock(\App\Services\Browser\BrowserTaskQueue::class)->shouldReceive('run')->once()
            ->andReturn(['unavailable' => ['target_id' => '987', 'author' => 'visitor', 'reason' => 'account_suspended']]);

        $url = '/api/community-manager/inbox/'.$item->id.'/context';
        $this->getJson($url)->assertConflict()->assertJsonPath('review_reason', 'x_account_suspended')->assertJsonPath('retry_at', null);
        $this->getJson($url)->assertConflict()->assertJsonPath('skip_item', true);
        $this->getJson('/api/community-manager/accounts/'.$account->id.'/inbox?agent_queue=1')->assertOk()->assertJsonCount(0, 'items');
        $this->assertSame('ignored', $item->fresh()->status);
        $this->assertSame(0, (int) $item->fresh()->reply_attempts);
    }

    public function test_api_filtre_une_reaction_a_notre_reponse_mais_garde_la_question(): void
    {
        $user = User::factory()->create(['role' => 'manager']);
        $persona = Persona::create(['name' => 'Persona', 'system_prompt' => 'Sobre', 'is_active' => true]);
        $account = SocialAccount::create([
            'name' => 'Compte Instagram', 'platform_id' => Platform::firstOrCreate(['slug' => 'instagram'], ['name' => 'Instagram'])->id,
            'platform_account_id' => '123', 'persona_id' => $persona->id,
        ]);
        $account->users()->attach($user, ['is_active' => true]);
        Sanctum::actingAs($user);
        $base = ['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'posted_at' => now()->subMinute(), 'author_external_id' => '456'];
        InboxItem::create($base + ['type' => 'comment', 'external_id' => 'root', 'content' => 'Jolie tenue',
            'reply_content' => 'Merci', 'reply_external_id' => 'ours', 'replied_at' => now()->subHour(), 'status' => 'replied']);
        $question = InboxItem::create($base + ['type' => 'reply', 'external_id' => 'q', 'parent_id' => 'root',
            'posted_at' => now(), 'content' => 'Où trouver cette veste', 'post_content' => 'Présentation de la collection hiver', 'status' => 'unread']);
        $reaction = InboxItem::create($base + ['type' => 'reply', 'external_id' => 'r', 'parent_id' => 'ours',
            'content' => 'Super, merci !', 'status' => 'unread']);
        $own = InboxItem::create(array_replace($base, ['type' => 'reply', 'external_id' => 'ours', 'parent_id' => 'root',
            'author_external_id' => '123', 'content' => 'Merci', 'status' => 'unread']));

        $context = '/api/community-manager/inbox/';
        $this->getJson($context.$question->id.'/context')->assertOk()->assertJsonPath('followup', true)->assertJsonPath('publication.text', 'Présentation de la collection hiver')
            ->assertJsonPath('conversation.2.mine', true);
        $question->update(['post_content' => null]);
        $this->getJson($context.$question->id.'/context')->assertConflict()->assertJsonPath('review_reason', 'context_changed');
        $this->getJson($context.$reaction->id.'/context')->assertConflict()->assertJsonPath('review_reason', 'not_relevant');
        $this->getJson($context.$own->id.'/context')->assertConflict()->assertJsonPath('review_reason', 'already_handled');
        $this->postJson('/api/inbox/'.$own->id.'/reply', ['reply_text' => 'Bonjour !'])->assertConflict();
    }

    public function test_le_lot_isole_le_compte_et_garde_le_contexte_de_chaque_cible(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-10T10:00:00Z')->setTimezone(config('app.timezone')));
        Http::preventStrayRequests();
        $user = User::factory()->create(['role' => 'manager']);
        $persona = Persona::create(['name' => 'Voix', 'system_prompt' => 'Répondre simplement', 'is_active' => true]);
        $platform = Platform::firstOrCreate(['slug' => 'instagram'], ['name' => 'Instagram']);
        $account = SocialAccount::create(['name' => 'Compte', 'platform_id' => $platform->id, 'persona_id' => $persona->id,
            'driver' => 'api', 'credentials' => ['access_token' => 'test']]);
        $other = SocialAccount::create(['name' => 'Autre compte', 'platform_id' => $platform->id]);
        $account->users()->attach($user, ['is_active' => true]);
        $other->users()->attach($user, ['is_active' => true]);
        $account->agentActions()->create(['action' => 'reply', 'enabled' => true, 'daily_limit' => 5]);
        Sanctum::actingAs($user, ['community-manager']);
        $base = ['social_account_id' => $account->id, 'platform_id' => $platform->id, 'type' => 'comment', 'status' => 'unread',
            'author_username' => 'lecteur', 'post_content' => 'La publication'];
        InboxItem::create(array_replace($base, ['external_id' => 'root', 'content' => 'Jolie veste', 'status' => 'replied',
            'posted_at' => now()->subHour(), 'replied_at' => now()->subMinutes(45), 'reply_external_id' => 'ours', 'reply_content' => 'Merci !']));
        $direct = InboxItem::create($base + ['external_id' => 'direct', 'content' => 'Très jolie', 'posted_at' => now()->subMinute()]);
        $question = InboxItem::create(array_replace($base, ['external_id' => 'question', 'parent_id' => 'ours',
            'type' => 'reply', 'content' => 'Où trouver cette veste ?', 'posted_at' => now()]));
        $reaction = InboxItem::create(array_replace($base, ['external_id' => 'reaction', 'parent_id' => 'ours',
            'type' => 'reply', 'content' => 'Merci !', 'posted_at' => now()]));
        InboxItem::create(array_replace($base, ['social_account_id' => $other->id, 'external_id' => 'other',
            'content' => 'Question sur un autre compte ?', 'posted_at' => now()]));

        $path = "/api/community-manager/accounts/{$account->id}/inbox/contexts";
        $batch = $this->getJson($path)->assertOk()->assertJsonCount(2, 'items')->assertJsonCount(1, 'skipped')
            ->assertJsonPath('persona.instructions', 'Répondre simplement')
            ->assertJsonPath('items.0.item_id', $question->id)->assertJsonPath('items.0.followup', true)
            ->assertJsonPath('items.0.publication.text', 'La publication')
            ->assertJsonPath('items.0.previous_messages.0.text', 'Jolie veste')
            ->assertJsonPath('items.0.previous_messages.1.text', 'Merci !')
            ->assertJsonPath('items.0.target.text', 'Où trouver cette veste ?')
            ->assertJsonMissingPath('items.0.persona')
            ->assertJsonPath('items.1.item_id', $direct->id)
            ->assertJsonPath('skipped.0.item_id', $reaction->id)->assertJsonPath('skipped.0.review_reason', 'not_relevant');
        $data = ['context_id' => $batch->json('items.0.context_id'), 'reply_text' => 'Je te retrouve la référence.'];
        $this->postJson("/api/community-manager/inbox/{$direct->id}/reply", $data)->assertConflict();
        $this->postJson("/api/community-manager/inbox/{$question->id}/reply", $data)->assertOk()->assertJsonPath('dry_run', true);
        $this->getJson($path.'?limit=6')->assertUnprocessable();
        $this->assertNull($question->fresh()->reply_scheduled_at);
        Http::assertNothingSent();
    }

    public function test_file_autonome_ecarte_les_anciennes_cibles_et_revalide_avant_envoi(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SendAutonomousInboxReply::class]);
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(12, 0));
        $user = User::factory()->create(['role' => 'manager']);
        $persona = Persona::create(['name' => 'Persona', 'system_prompt' => 'Sobre', 'is_active' => true]);
        $account = SocialAccount::create(['name' => 'Compte', 'platform_id' => Platform::firstOrCreate(['slug' => 'instagram'], ['name' => 'Instagram'])->id, 'persona_id' => $persona->id]);
        $account->users()->attach($user, ['is_active' => true]);
        $account->agentActions()->create(['action' => 'reply', 'enabled' => true, 'daily_limit' => 10]);
        Sanctum::actingAs($user);
        Http::preventStrayRequests();
        $base = ['social_account_id' => $account->id, 'platform_id' => $account->platform_id, 'type' => 'comment',
            'content' => 'Bonjour', 'post_content' => 'Une ancienne publication', 'status' => 'unread'];
        $old = InboxItem::create($base + ['external_id' => 'old', 'posted_at' => now()->subRealDays(14)->subSecond()]);
        InboxItem::create($base + ['external_id' => 'unknown', 'posted_at' => null]);
        $boundary = InboxItem::create($base + ['external_id' => 'boundary', 'posted_at' => now()->subRealDays(14)]);
        $inbox = "/api/community-manager/accounts/{$account->id}/inbox";
        $this->getJson($inbox.'?agent_queue=1')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $boundary->id);
        $this->getJson($inbox)->assertOk()->assertJsonCount(3, 'items');
        $this->getJson("/api/community-manager/inbox/{$old->id}/context")->assertConflict()
            ->assertJsonPath('review_reason', 'target_date_outside_window');
        $path = "/api/community-manager/inbox/{$boundary->id}";
        $context = $this->getJson($path.'/context')->assertOk()->json('context_id');
        $this->postJson($path.'/reply', ['context_id' => $context, 'reply_text' => 'Merci !', 'dry_run' => false])->assertAccepted();

        $this->travel(1)->second();
        (new \App\Jobs\SendAutonomousInboxReply($boundary->id))->handle(app(\App\Services\Inbox\InboxReplyService::class));

        $this->assertNull($boundary->fresh()->reply_scheduled_at);
        $this->assertSame(0, (int) $boundary->fresh()->reply_attempts);
        $this->assertSame(0, \App\Models\AccountActionEvent::count());
        Http::assertNothingSent();
    }

    public function test_x_accepte_uniquement_les_destinataires_de_reponse_avant_le_texte_exact(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-08T14:00:00Z')->setTimezone(config('app.timezone')));
        $u = User::factory()->create(['role' => 'manager']);
        $p = Persona::create(['name' => 'Voix', 'system_prompt' => 'Sobre', 'is_active' => true]);
        $a = SocialAccount::create(['name' => 'Compte attribué', 'platform_id' => Platform::firstOrCreate(['slug' => 'twitter'], ['name' => 'X'])->id, 'driver' => 'browser', 'browser_username' => 'owner', 'persona_id' => $p->id]);
        $a->users()->attach($u, ['is_active' => true]);
        Sanctum::actingAs($u);
        $item = InboxItem::create(['social_account_id' => $a->id, 'platform_id' => $a->platform_id, 'type' => 'comment', 'external_id' => '987', 'author_username' => 'visitor', 'content' => '@owner @other Très jolie tenue 😊', 'status' => 'unread', 'posted_at' => now()->subMinute()]);
        $data = ['context_complete' => true, 'target' => ['id' => '987', 'author' => 'visitor', 'text' => 'Très jolie tenue 😊'], 'conversation' => [['author' => 'visitor', 'text' => 'Très jolie tenue 😊']], 'visible_replies' => []];
        $updated = ['id' => '987', 'author' => 'visitor', 'text' => 'Version visible sur X'];
        $this->mock(\App\Services\Browser\BrowserTaskQueue::class)->shouldReceive('run')->times(3)->andReturn(
            $data,
            array_replace($data, ['target' => $updated, 'conversation' => [$updated]]),
            array_replace($data, ['target' => ['id' => '987', 'author' => 'visitor', 'text' => 'Texte sans preuve']]),
        );
        $url = '/api/community-manager/inbox/'.$item->id.'/context';
        $this->getJson($url)->assertOk();
        $this->assertSame('Très jolie tenue 😊', $item->fresh()->content);
        $this->getJson($url)->assertOk()->assertJsonPath('content', $updated['text']);
        $this->getJson($url)->assertConflict()->assertJsonPath('skip_item', true)->assertJsonPath('review_reason', 'x_context_changed');
        $this->getJson($url)->assertConflict()->assertJsonPath('skip_item', true);
        $this->assertSame($updated['text'], $item->fresh()->content);
        $this->assertSame(0, (int) $item->fresh()->reply_attempts);
    }
}
