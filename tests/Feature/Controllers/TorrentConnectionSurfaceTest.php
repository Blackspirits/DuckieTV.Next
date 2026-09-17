<?php

namespace Tests\Feature\Controllers;

use App\Services\AutoDownloadLifecycleService;
use App\Services\TorrentClients\TorrentClientInterface;
use App\Services\TorrentClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_status_reports_disconnected_when_remote_read_loses_connection(): void
    {
        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturnTrue();
        $client->shouldReceive('getTorrents')->once()->andReturn([]);
        $client->shouldReceive('isConnected')->once()->andReturnFalse();
        $client->shouldReceive('getName')->twice()->andReturn('MockClient');
        $client->shouldReceive('getId')->once()->andReturn('mock-client');

        $service = Mockery::mock(TorrentClientService::class);
        $service->shouldReceive('getActiveClient')->twice()->andReturn($client);
        $this->app->instance(TorrentClientService::class, $service);

        $lifecycle = Mockery::mock(AutoDownloadLifecycleService::class);
        $lifecycle->shouldReceive('recordClientConnectivity')
            ->once()
            ->with('mock-client', false)
            ->andReturn(false);
        $this->app->instance(AutoDownloadLifecycleService::class, $lifecycle);

        $this->getJson(route('torrents.status'))
            ->assertOk()
            ->assertJson([
                'connected' => false,
                'client' => 'MockClient',
                'active_count' => 0,
                'torrents' => [],
                'error' => 'Connection to MockClient was lost while reading torrents.',
            ]);
    }

    public function test_status_does_not_expose_connection_exception_details(): void
    {
        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')
            ->once()
            ->andThrow(new \RuntimeException('remote-response-secret'));
        $client->shouldReceive('getName')->twice()->andReturn('MockClient');
        $client->shouldReceive('getId')->once()->andReturn('mock-client');

        $service = Mockery::mock(TorrentClientService::class);
        $service->shouldReceive('getActiveClient')->twice()->andReturn($client);
        $this->app->instance(TorrentClientService::class, $service);

        $lifecycle = Mockery::mock(AutoDownloadLifecycleService::class);
        $lifecycle->shouldReceive('recordClientConnectivity')
            ->once()
            ->with('mock-client', false)
            ->andReturn(false);
        $this->app->instance(AutoDownloadLifecycleService::class, $lifecycle);

        $response = $this->getJson(route('torrents.status'));

        $response->assertOk()->assertJson([
            'connected' => false,
            'client' => 'MockClient',
            'active_count' => 0,
            'torrents' => [],
            'error' => 'Connection to MockClient failed. Check your settings and ensure the client is running.',
        ]);
        $this->assertStringNotContainsString('remote-response-secret', $response->getContent());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function torrentClientActionFailureProvider(): array
    {
        return [
            'start' => ['torrents.start', 'startTorrent', 'Failed to start torrent'],
            'stop' => ['torrents.stop', 'stopTorrent', 'Failed to stop torrent'],
            'pause' => ['torrents.pause', 'pauseTorrent', 'Failed to pause torrent'],
            'remove' => ['torrents.remove', 'removeTorrent', 'Failed to remove torrent'],
        ];
    }

    #[DataProvider('torrentClientActionFailureProvider')]
    public function test_torrent_client_actions_do_not_expose_exception_details(
        string $routeName,
        string $method,
        string $expectedError
    ): void {
        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturnTrue();
        $client->shouldReceive($method)
            ->once()
            ->andThrow(new \RuntimeException('remote-response-secret'));

        $service = Mockery::mock(TorrentClientService::class);
        $service->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->app->instance(TorrentClientService::class, $service);

        $response = $this->postJson(route($routeName, ['infoHash' => '0123456789abcdef0123456789abcdef01234567']));

        $response->assertStatus(500)->assertJson(['error' => $expectedError]);
        $this->assertStringNotContainsString('remote-response-secret', $response->getContent());
    }

    public function test_torrent_settings_test_does_not_expose_connection_exception_details(): void
    {
        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('readConfig')->once();
        $client->shouldReceive('connect')
            ->once()
            ->andThrow(new \RuntimeException('remote-response-secret'));
        $client->shouldReceive('getId')->once()->andReturn('mock-client');
        $client->shouldReceive('getName')->once()->andReturn('MockClient');

        $service = Mockery::mock(TorrentClientService::class);
        $service->shouldReceive('getAvailableClients')->andReturn([]);
        $service->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->app->instance(TorrentClientService::class, $service);

        $lifecycle = Mockery::mock(AutoDownloadLifecycleService::class);
        $lifecycle->shouldReceive('recordClientConnectivity')
            ->once()
            ->with('mock-client', false)
            ->andReturn(false);
        $lifecycle->shouldNotReceive('dispatchIfEligible');
        $this->app->instance(AutoDownloadLifecycleService::class, $lifecycle);

        $response = $this->postJson(route('settings.update', 'torrent'), [
            'test' => 1,
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'connection_success' => false,
            'connection_error' => 'Connection to MockClient failed. Check your settings and server status.',
        ]);
        $this->assertStringNotContainsString('remote-response-secret', $response->getContent());
    }

    public function test_torrent_settings_test_uses_the_registered_client_connection_path(): void
    {
        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('readConfig')->once();
        $client->shouldReceive('connect')->once()->andReturnTrue();
        $client->shouldReceive('getName')->once()->andReturn('MockClient');
        $client->shouldReceive('getId')->once()->andReturn('mock-client');

        $service = Mockery::mock(TorrentClientService::class);
        $service->shouldReceive('getAvailableClients')->andReturn([]);
        $service->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->app->instance(TorrentClientService::class, $service);

        $lifecycle = Mockery::mock(AutoDownloadLifecycleService::class);
        $lifecycle->shouldReceive('recordClientConnectivity')
            ->once()
            ->with('mock-client', true)
            ->andReturn(false);
        $lifecycle->shouldReceive('dispatchIfEligible')->once()->andReturn(false);
        $this->app->instance(AutoDownloadLifecycleService::class, $lifecycle);

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
