<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExtensionErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_erreurs_companion_sont_regroupees_puis_purgees_apres_sept_jours(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $error = ['context' => 'content', 'message' => 'Cannot read properties of null', 'version' => '0.18.47',
            'stack' => "TypeError: Cannot read properties of null\n    at chrome-extension://abc/tiktok/comments.js:12:3", 'page' => 'https://www.tiktok.com/tiktokstudio/comment'];
        $this->postJson('/api/extension/errors', $error)->assertNoContent();
        $this->postJson('/api/extension/errors', $error)->assertNoContent();
        $this->postJson('/api/extension/errors', ['message' => 'Autre'] + $error)->assertNoContent();
        $this->assertSame(2, DB::table('extension_errors')->count());
        $this->assertSame(2, (int) DB::table('extension_errors')->where('message', $error['message'])->value('occurrences'));
        $this->postJson('/api/extension/errors', ['context' => 'page'] + $error)->assertUnprocessable();

        $this->travel(8)->days();
        $this->artisan('agent:purge-logs')->assertSuccessful();
        $this->assertSame(0, DB::table('extension_errors')->count());
    }
}
