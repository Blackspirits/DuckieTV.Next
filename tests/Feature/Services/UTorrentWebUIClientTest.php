<?php

namespace Tests\Feature\Services;

use App\Services\SettingsService;
use App\Services\TorrentClients\UTorrentWebUIClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UTorrentWebUIClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_refresh_retry_is_bounded_to_one_attempt(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('utorrentwebui.server', 'http://localhost');
        $settings->set('utorrentwebui.port', 8080);
        $settings->set('utorrentwebui.use_auth', false);

        Http::fakeSequence()
            ->push('<html><div id="token">first-token</div></html>', 200, ['Set-Cookie' => 'GUID=first-cookie;'])
            ->push([], 401)
            ->push('<html><div id="token">second-token</div></html>', 200, ['Set-Cookie' => 'GUID=second-cookie;'])
            ->push([], 401);

        $client = new UTorrentWebUIClient($settings);

        $this->assertTrue($client->connect());
        $this->assertSame([], $client->getTorrents());

        Http::assertSentCount(4);
    }
}
