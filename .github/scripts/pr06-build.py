from pathlib import Path

PREREQUISITE = 'bdfce3d14560e4184e56b065c7304af017566e56'


def replace_exact(path, old, new, expected=1):
    file = Path(path)
    text = file.read_text()
    count = text.count(old)
    if count != expected:
        raise SystemExit(f'{path}: expected {expected} occurrence(s), found {count}')
    file.write_text(text.replace(old, new))


replace_exact(
    'app/Http/Controllers/TorrentController.php',
    'use Illuminate\\Http\\Request;\n',
    '',
)

replace_exact(
    'app/Http/Controllers/TorrentController.php',
    '''    /**
     * Attempt to connect to the configured torrent client.
     */
    public function connect(Request $request): JsonResponse
    {
        $config = $request->all();
        $client = $config['torrenting.client'] ?? 'uTorrent';

        \\App\\Events\\TorrentConnectionStatus::dispatch('connecting', $client, 'Connecting to '.$client.'...');
        \\App\\Jobs\\AttemptTorrentConnection::dispatch($client, $config);

        return response()->json(['success' => true, 'message' => 'Connection attempt started...']);
    }

''',
    '',
)

replace_exact(
    'routes/web.php',
    '''// Torrent Search
Route::get('/debug-engines', function () {
    $service = app(\\App\\Services\\TorrentSearchService::class);

    return response()->json([
        'engines' => array_keys($service->getSearchEngines()),
        'count' => count($service->getSearchEngines()),
    ]);
});

Route::prefix('torrents')->group(function () {
''',
    '''// Torrent Search
Route::prefix('torrents')->group(function () {
''',
)

replace_exact(
    'routes/web.php',
    "    Route::post('/connect', [\\App\\Http\\Controllers\\TorrentController::class, 'connect'])->name('torrents.connect');\n",
    '',
)

job = Path('app/Jobs/AttemptTorrentConnection.php')
if not job.exists():
    raise SystemExit('app/Jobs/AttemptTorrentConnection.php: expected file to exist')
job.unlink()

Path('tests/Feature/Controllers/TorrentConnectionSurfaceTest.php').write_text(r'''<?php

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
''')
