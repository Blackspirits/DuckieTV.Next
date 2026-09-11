<?php

namespace Tests\Feature\Controllers;

use App\Models\Episode;
use App\Models\Serie;
use App\Services\TorrentClients\TorrentClientInterface;
use App\Services\TorrentClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TorrentAddTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_add_endpoint_successfully_adds_magnet()
    {
        $mockClient = Mockery::mock(TorrentClientInterface::class);
        $mockClient->shouldReceive('connect')->andReturn(true);
        $mockClient->shouldReceive('addMagnet')
            ->with('magnet:?xt=urn:btih:abc', null, 'DuckieTV')
            ->andReturn(true);

        $mockService = Mockery::mock(TorrentClientService::class);
        $mockService->shouldReceive('getActiveClient')->andReturn($mockClient);
        $this->app->instance(TorrentClientService::class, $mockService);

        $response = $this->postJson(route('torrents.add'), [
            'magnet' => 'magnet:?xt=urn:btih:abc',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Torrent added successfully',
        ]);
    }

    public function test_add_endpoint_successfully_adds_torrent_url()
    {
        $mockClient = Mockery::mock(TorrentClientInterface::class);
        $mockClient->shouldReceive('connect')->andReturn(true);
        $mockClient->shouldReceive('addTorrentByUrl')
            ->with('http://example.com/file.torrent', '1234567890123456789012345678901234567890', 'Test.Release', null, 'DuckieTV')
            ->andReturn(true);

        $mockService = Mockery::mock(TorrentClientService::class);
        $mockService->shouldReceive('getActiveClient')->andReturn($mockClient);
        $this->app->instance(TorrentClientService::class, $mockService);

        $response = $this->postJson(route('torrents.add'), [
            'url' => 'http://example.com/file.torrent',
            'infoHash' => '1234567890123456789012345678901234567890',
            'releaseName' => 'Test.Release',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_add_endpoint_returns_error_when_no_client_configured()
    {
        $mockService = Mockery::mock(TorrentClientService::class);
        $mockService->shouldReceive('getActiveClient')->andReturn(null);
        $this->app->instance(TorrentClientService::class, $mockService);

        $response = $this->postJson(route('torrents.add'), [
            'magnet' => 'magnet:?xt=urn:btih:abc',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'No torrent client configured']);
    }

    public function test_add_endpoint_returns_error_when_connection_fails()
    {
        $mockClient = Mockery::mock(TorrentClientInterface::class);
        $mockClient->shouldReceive('connect')->andReturn(false);

        $mockService = Mockery::mock(TorrentClientService::class);
        $mockService->shouldReceive('getActiveClient')->andReturn($mockClient);
        $this->app->instance(TorrentClientService::class, $mockService);

        $response = $this->postJson(route('torrents.add'), [
            'magnet' => 'magnet:?xt=urn:btih:abc',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Could not connect to torrent client']);
    }

    public function test_add_endpoint_validates_required_fields_for_url()
    {
        $response = $this->postJson(route('torrents.add'), [
            'url' => 'http://example.com/file.torrent',
            // infohash and releasename are intentionally missing
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['releaseName']);
    }

    public function test_add_endpoint_validates_magnet_prefix()
    {
        $response = $this->postJson(route('torrents.add'), [
            'magnet' => 'not-a-magnet',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['magnet']);
    }

    public function test_tracked_magnet_uses_embedded_btih_over_conflicting_infohash(): void
    {
        $serie = Serie::create(['name' => 'Identity Test', 'trakt_id' => 92001]);
        $episode = Episode::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 1,
            'episodenumber' => 1,
            'trakt_id' => 92002,
        ]);

        $actualHash = '0123456789ABCDEF0123456789ABCDEF01234567';
        $conflictingHash = str_repeat('f', 40);
        $magnet = 'magnet:?xt=urn:btih:'.$actualHash;

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('addMagnet')->once()->with($magnet, null, 'DuckieTV')->andReturn(true);

        $service = Mockery::mock(TorrentClientService::class);
        $service->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->app->instance(TorrentClientService::class, $service);

        $response = $this->postJson(route('torrents.add'), [
            'magnet' => $magnet,
            'infoHash' => $conflictingHash,
            'episode_id' => $episode->id,
        ]);

        $canonical = strtolower($actualHash);
        $response->assertOk()->assertJson(['success' => true, 'infoHash' => $canonical]);
        $this->assertSame($canonical, $episode->fresh()->magnetHash);
    }

    public function test_tracked_torrent_url_requires_canonical_identity_before_add(): void
    {
        $serie = Serie::create(['name' => 'URL Identity Test', 'trakt_id' => 93001]);
        $episode = Episode::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 1,
            'episodenumber' => 1,
            'trakt_id' => 93002,
        ]);

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldNotReceive('addTorrentByUrl');

        $service = Mockery::mock(TorrentClientService::class);
        $service->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->app->instance(TorrentClientService::class, $service);

        $response = $this->postJson(route('torrents.add'), [
            'url' => 'http://example.com/tracked.torrent',
            'releaseName' => 'Tracked.Release',
            'episode_id' => $episode->id,
        ]);

        $response->assertStatus(422)->assertJson([
            'error' => 'Cannot track episode torrent without a canonical BTIH',
        ]);
        $this->assertFalse($episode->fresh()->isDownloaded());
        $this->assertNull($episode->fresh()->magnetHash);
    }

    public function test_torrent_url_base32_identity_is_canonicalized_before_tracked_success(): void
    {
        $serie = Serie::create(['name' => 'Base32 URL Test', 'trakt_id' => 94001]);
        $episode = Episode::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 1,
            'episodenumber' => 1,
            'trakt_id' => 94002,
        ]);

        $url = 'http://example.com/base32.torrent';
        $base32 = 'AAISEM2EKVTHPCEZVK54ZXPO74ABCIRT';
        $canonical = '00112233445566778899aabbccddeeff00112233';

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->once()->andReturn(true);
        $client->shouldReceive('addTorrentByUrl')
            ->once()
            ->with($url, $canonical, 'Base32.Release', null, 'DuckieTV')
            ->andReturn(true);

        $service = Mockery::mock(TorrentClientService::class);
        $service->shouldReceive('getActiveClient')->once()->andReturn($client);
        $this->app->instance(TorrentClientService::class, $service);

        $response = $this->postJson(route('torrents.add'), [
            'url' => $url,
            'infoHash' => strtolower($base32),
            'releaseName' => 'Base32.Release',
            'episode_id' => $episode->id,
        ]);

        $response->assertOk()->assertJson(['success' => true, 'infoHash' => $canonical]);
        $this->assertSame($canonical, $episode->fresh()->magnetHash);
    }

    public static function episodeStateCases(): array
    {
        return [
            'magnet success' => ['magnet', 'success'],
            'magnet false' => ['magnet', 'false'],
            'magnet exception' => ['magnet', 'exception'],
            'url success' => ['url', 'success'],
            'url false' => ['url', 'false'],
            'url exception' => ['url', 'exception'],
        ];
    }

    #[DataProvider('episodeStateCases')]
    public function test_episode_state_changes_only_after_torrent_add_succeeds(string $kind, string $outcome): void
    {
        $serie = Serie::create([
            'name' => 'State Ordering Test',
            'trakt_id' => 91001,
        ]);
        $episode = Episode::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 1,
            'episodenumber' => 1,
            'trakt_id' => 91002,
        ]);

        $hash = $kind === 'magnet'
            ? '0123456789abcdef0123456789abcdef01234567'
            : '3333333333333333333333333333333333333333';
        $magnet = 'magnet:?xt=urn:btih:'.$hash;
        $url = 'http://example.com/state-ordering.torrent';

        $client = Mockery::mock(TorrentClientInterface::class);
        $client->shouldReceive('connect')->andReturn(true);
        $add = $kind === 'magnet'
            ? $client->shouldReceive('addMagnet')->with($magnet, null, 'DuckieTV')
            : $client->shouldReceive('addTorrentByUrl')->with($url, $hash, 'State.Ordering', null, 'DuckieTV');

        if ($outcome === 'exception') {
            $add->andThrow(new \RuntimeException('client add failed'));
        } else {
            $add->andReturn($outcome === 'success');
        }

        $service = Mockery::mock(TorrentClientService::class);
        $service->shouldReceive('getActiveClient')->andReturn($client);
        $this->app->instance(TorrentClientService::class, $service);

        $payload = $kind === 'magnet'
            ? ['magnet' => $magnet, 'episode_id' => $episode->id]
            : [
                'url' => $url,
                'infoHash' => $hash,
                'releaseName' => 'State.Ordering',
                'episode_id' => $episode->id,
            ];

        $response = $this->postJson(route('torrents.add'), $payload);

        if ($outcome === 'success') {
            $response->assertOk()->assertJson(['success' => true]);
        } elseif ($outcome === 'false') {
            $response->assertStatus(422)->assertJson(['error' => 'Failed to add torrent to client']);
        } else {
            $response->assertStatus(500)->assertJson(['error' => 'client add failed']);
        }

        $episode->refresh();
        if ($outcome === 'success') {
            $this->assertTrue($episode->isDownloaded());
            $this->assertSame(strtolower($hash), $episode->magnetHash);
        } else {
            $this->assertFalse($episode->isDownloaded());
            $this->assertNull($episode->magnetHash);
        }
    }
}
