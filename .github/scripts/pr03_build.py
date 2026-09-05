from pathlib import Path

controller = Path('app/Http/Controllers/TorrentController.php')
source = controller.read_text()

old_link = '''            // Link to episode if provided
            if ($episodeId) {
                /** @var \\App\\Models\\Episode|null $episode */
                $episode = \\App\\Models\\Episode::find($episodeId);
                if ($episode) {
                    // Update magnetHash if we found one (either passed or extracted)
                    if ($infoHash) {
                        $episode->update(['magnetHash' => $infoHash]);
                    }
                    $episode->markDownloaded();
                    // Optional: You might want to dispatch an event here if needed
                }
            }
'''
new_link = '''            // Resolve the episode now, but do not mutate local state until the external add succeeds.
            $episode = null;
            if ($episodeId) {
                /** @var \\App\\Models\\Episode|null $episode */
                $episode = \\App\\Models\\Episode::find($episodeId);
            }
'''
if old_link not in source:
    raise SystemExit('episode pre-mutation block not found')
source = source.replace(old_link, new_link, 1)

old_result = '''            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Torrent added successfully',
                    'infoHash' => $infoHash, // Return the hash so specific UI logic can use it if needed
                ]);
            }

            return response()->json(['error' => 'Failed to add torrent to client'], 422);
'''
new_result = '''            if (! $success) {
                return response()->json(['error' => 'Failed to add torrent to client'], 422);
            }

            if ($episode) {
                try {
                    \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($episode, $infoHash) {
                        if ($infoHash) {
                            $episode->update(['magnetHash' => $infoHash]);
                        }
                        $episode->markDownloaded();
                    });
                } catch (Exception) {
                    return response()->json([
                        'error' => 'Torrent added, but failed to update episode state',
                        'torrent_added' => true,
                    ], 500);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Torrent added successfully',
                'infoHash' => $infoHash, // Return the hash so specific UI logic can use it if needed
            ]);
'''
if old_result not in source:
    raise SystemExit('success/result block not found')
controller.write_text(source.replace(old_result, new_result, 1))

test = Path('tests/Feature/Controllers/TorrentAddTest.php')
source = test.read_text()
import_anchor = 'use App\\Services\\TorrentClients\\TorrentClientInterface;\n'
imports = (
    'use App\\Models\\Episode;\n'
    'use App\\Models\\Serie;\n'
    'use App\\Services\\TorrentClients\\TorrentClientInterface;\n'
    'use PHPUnit\\Framework\\Attributes\\DataProvider;\n'
)
if import_anchor not in source:
    raise SystemExit('test import anchor not found')
source = source.replace(import_anchor, imports, 1)

marker = '\n}\n'
if not source.endswith(marker):
    raise SystemExit('unexpected TorrentAddTest class ending')

extra = r'''

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
            $this->assertSame($kind === 'magnet' ? strtoupper($hash) : $hash, $episode->magnetHash);
        } else {
            $this->assertFalse($episode->isDownloaded());
            $this->assertNull($episode->magnetHash);
        }
    }
'''

test.write_text(source[:-len(marker)] + extra + marker)
