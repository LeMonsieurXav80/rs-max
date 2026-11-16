<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Les réponses du compte lui-même, importées avec les fils, ne comptent pas comme des messages à traiter. */
class InboxOwnMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_message_du_compte_est_classe_traite_et_reste_dans_le_fil(): void
    {
        $account = SocialAccount::create(['platform_id' => Platform::firstOrCreate(['slug' => 'instagram'], ['name' => 'Instagram'])->id,
            'name' => 'wildycaro', 'platform_account_id' => '178414']);
        $row = fn (string $id, string $author, ?string $authorId) => ['external_id' => $id, 'type' => 'reply', 'author_username' => $author,
            'author_external_id' => $authorId, 'content' => 'Merci 😊', 'conversation_key' => 'fil', 'status' => 'unread', 'posted_at' => now()];
        $store = \Closure::bind(fn ($a, $items) => $this->storeItems($a, $items), app(InboxSyncService::class), InboxSyncService::class);

        $store($account->fresh('platform'), collect([$row('1', 'fan', '9'), $row('2', 'wildycaro', '178414')]));

        $own = InboxItem::where('external_id', '2')->sole();
        $this->assertSame(['replied', 'own', 'fil'], [$own->status, $own->agent_review_reason, $own->conversation_key]);
        $this->assertSame('unread', InboxItem::where('external_id', '1')->sole()->status);
    }
}
