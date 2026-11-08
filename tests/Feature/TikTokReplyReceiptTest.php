<?php

namespace Tests\Feature;

use App\Models\BrowserTask;
use App\Models\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokReplyReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_recu_avant_verification_ne_peut_pas_marquer_le_message_publie_et_resiste_a_la_fin_partielle(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $a = SocialAccount::create(['platform_id' => Platform::where('slug', 'tiktok')->firstOrFail()->id, 'name' => 'Compte attribué']);
        $t = BrowserTask::create(['social_account_id' => $a->id, 'profile' => 'test', 'kind' => 'tiktok.reply', 'status' => 'running', 'payload' => []]);
        $receipt = ['text_matched' => true, 'comment_id' => '1234567890123456789', 'parent_id' => '42', 'post_id' => '99', 'post_type' => 'video', 'http_status' => 200];
        ksort($receipt);
        $url = '/api/extension/tasks/'.$t->id;
        $this->postJson($url.'/checkpoint', ['result' => ['submission' => $receipt + ['cookie' => 'NEVER'], 'send_clicked' => true, 'input_mode' => 'native', 'confirmed' => true, 'proof' => 'after_reload']])->assertOk();
        $this->assertSame($receipt, $t->fresh()->result['submission']);
        $this->assertArrayNotHasKey('confirmed', $t->fresh()->result);
        $this->assertSame('running', $t->fresh()->status);
        $this->postJson($url.'/checkpoint', ['result' => ['submission' => array_reverse($receipt, true)]])->assertOk();
        $this->postJson($url.'/checkpoint', ['result' => ['send_clicked' => false]])->assertConflict();
        $this->postJson($url.'/checkpoint', ['result' => ['submission' => array_replace($receipt, ['comment_id' => '123'])]])->assertConflict();
        $this->postJson($url.'/finish', ['status' => 'uncertain', 'result' => ['confirmed' => false], 'error' => 'Relecture interrompue'])->assertOk();
        $this->assertSame($receipt, $t->fresh()->result['submission']);
        $this->assertTrue($t->fresh()->result['send_clicked']);
        $this->assertFalse($t->fresh()->result['confirmed']);
        $this->postJson($url.'/checkpoint', ['result' => ['submission' => $receipt]])->assertConflict();
    }
}
