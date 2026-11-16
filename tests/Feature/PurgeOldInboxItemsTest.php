<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Messages reçus de plus d'un mois supprimés chaque nuit, et jamais réimportés. */
class PurgeOldInboxItemsTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = SocialAccount::create(['platform_id' => Platform::firstOrCreate(['slug' => 'instagram'], ['name' => 'Instagram'])->id,
            'name' => 'compte', 'platform_account_id' => '1']);
    }

    private function item(string $id, array $extra): InboxItem
    {
        return InboxItem::create(['social_account_id' => $this->account->id, 'platform_id' => $this->account->platform_id,
            'external_id' => $id, 'type' => 'comment', 'content' => 'Bravo', 'status' => 'unread'] + $extra);
    }

    public function test_seuls_les_messages_de_plus_dun_mois_sans_reponse_en_cours_sont_supprimes(): void
    {
        $recent = $this->item('recent', ['posted_at' => now()->subDays(10)]);
        $this->item('ancien', ['posted_at' => now()->subDays(40)]);
        $inFlight = $this->item('en-cours', ['posted_at' => now()->subDays(40), 'reply_scheduled_at' => now()]);

        $this->artisan('inbox:purge-old')->assertSuccessful();
        $this->assertSame(3, InboxItem::count());

        $this->artisan('inbox:purge-old --commit')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$recent->id, $inFlight->id], InboxItem::pluck('id')->all());
    }

    public function test_limport_ignore_un_message_de_plus_dun_mois(): void
    {
        $store = \Closure::bind(fn ($a, $items) => $this->storeItems($a, $items), app(InboxSyncService::class), InboxSyncService::class);
        $row = fn (string $id, int $days) => ['external_id' => $id, 'type' => 'comment', 'author_username' => 'fan', 'content' => 'Bravo',
            'status' => 'unread', 'posted_at' => now()->subDays($days)];

        $store($this->account->fresh('platform'), collect([$row('vieux', 45), $row('neuf', 2)]));

        $this->assertSame(['neuf'], InboxItem::pluck('external_id')->all());
    }
}
