<?php

namespace Tests\Feature\Controllers;

use App\Services\TorrentClients\TorrentClientInterface;
use App\Services\TorrentClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class TorrentConnectionSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug_engine_inventory_is_not_exposed_as_a_production_route(): void
    {
        $this->get('/debug-engines')->assertNotFound();
    }

    public function test_obsolete_simulated_torrent_connect_route_is_not_available(): void
    {
        $this->assertFalse(Route::has('torrents.connect'));

        $this->postJson('/torrents/connect', [
            'torrenting.client' => 'Transmission',
            'transmission.password' => 'must-not-be-queued',
        ])->assertStatus(405);
    }

    public function test_torrent_settings_test_uses_the_registered_client_connection_path(): void
    {
        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('readConfig')->once();
        $client->shouldReceive('connect')->once()->andReturnTrue();
        $client->shouldReceive('getName')->once()->andReturn('MockClient');

        $service = Mockery::mock(TorrentClientService::class);
        $service->shouldReceive('getAvailableClients')->andReturn([]);
        $service->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->app->instance(TorrentClientService::class, $service);

        $response = $this->postJson(route('settings.update', 'torrent'), [
            'test' => 1,
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'connection_success' => true,
            'message' => 'Connected to MockClient successfully!',
        ]);
    }
}
