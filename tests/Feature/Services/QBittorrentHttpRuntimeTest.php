<?php

namespace Tests\Feature\Services;

use App\Services\SettingsService;
use App\Services\TorrentClients\QBittorrentClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class QBittorrentHttpRuntimeTest extends TestCase
{
    private ?Process $server = null;

    private ?string $logPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->server->isRunning()) {
            $this->server->stop(1);
        }

        if ($this->logPath !== null && is_file($this->logPath)) {
            @unlink($this->logPath);
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_real_http_transport_satisfies_auth_origin_and_reuses_cached_sid(): void
    {
        $port = $this->startServer();
        $settings = $this->settings($port);

        $first = new QBittorrentClient($settings);
        $this->assertTrue($first->connect());
        $this->assertTrue($first->isConnected());

        $torrents = $first->getTorrents();
        $this->assertCount(1, $torrents);
        $this->assertSame('00112233445566778899AABBCCDDEEFF00112233', $torrents[0]->infoHash);
        $this->assertSame('qBittorrent Runtime Evidence', $torrents[0]->name);
        $this->assertSame(50.0, $torrents[0]->progress);
        $this->assertTrue($torrents[0]->isStarted());

        $magnet = 'magnet:?xt=urn:btih:00112233445566778899aabbccddeeff00112233';
        $this->assertTrue($first->addMagnet($magnet));

        $second = new QBittorrentClient($settings);
        $this->assertTrue($second->connect());
        $this->assertCount(1, $second->getTorrents());

        $requests = $this->requests();
        $this->assertCount(4, $requests);
        $this->assertSame('/api/v2/auth/login', $requests[0]['path']);
        $this->assertSame('http://127.0.0.1:'.$port, $requests[0]['origin']);
        $this->assertSame('', $requests[0]['referer']);
        $this->assertSame('/api/v2/torrents/info', $requests[1]['path']);
        $this->assertStringContainsString('SID=runtime-sid', $requests[1]['cookie']);
        $this->assertSame('/api/v2/torrents/add', $requests[2]['path']);
        $this->assertStringContainsString('SID=runtime-sid', $requests[2]['cookie']);
        $this->assertStringContainsString(urlencode($magnet), $requests[2]['body']);
        $this->assertSame('/api/v2/torrents/info', $requests[3]['path']);
        $this->assertStringContainsString('SID=runtime-sid', $requests[3]['cookie']);
    }

    public function test_real_http_transport_rejects_stale_cached_sid_then_logs_in_again(): void
    {
        $port = $this->startServer();
        Cache::put($this->sessionCacheKey($port), Crypt::encryptString('SID=stale'), 300);

        $client = new QBittorrentClient($this->settings($port));

        $this->assertTrue($client->connect());
        $this->assertTrue($client->isConnected());
        $this->assertSame(
            'SID=runtime-sid; path=/; HttpOnly',
            Crypt::decryptString(Cache::get($this->sessionCacheKey($port)))
        );

        $requests = $this->requests();
        $this->assertCount(2, $requests);
        $this->assertSame('/api/v2/torrents/info', $requests[0]['path']);
        $this->assertStringContainsString('SID=stale', $requests[0]['cookie']);
        $this->assertSame('/api/v2/auth/login', $requests[1]['path']);
        $this->assertSame('http://127.0.0.1:'.$port, $requests[1]['origin']);
        $this->assertSame('', $requests[1]['referer']);
    }

    private function startServer(): int
    {
        $port = $this->reserveLocalPort();
        $this->logPath = tempnam(sys_get_temp_dir(), 'duckietv-qbit-log-');
        $this->assertNotFalse($this->logPath);

        $router = base_path('tests/Fixtures/QBittorrentWebApi/router.php');
        $this->server = new Process([
            PHP_BINARY,
            '-S',
            '127.0.0.1:'.$port,
            $router,
        ], base_path(), [
            'QBITTORRENT_EMULATOR_LOG' => $this->logPath,
        ]);
        $this->server->start();
        $this->waitForServer($port);

        return $port;
    }

    private function settings(int $port): SettingsService
    {
        $values = [
            'qbittorrent32plus.server' => 'http://127.0.0.1',
            'qbittorrent32plus.port' => $port,
            'qbittorrent32plus.use_auth' => true,
            'qbittorrent32plus.username' => 'duckie',
            'qbittorrent32plus.password' => 'secret',
        ];

        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturnUsing(
                fn (string $key, mixed $default = null): mixed => array_key_exists($key, $values)
                    ? $values[$key]
                    : $default
            );

        return $settings;
    }

    private function sessionCacheKey(int $port): string
    {
        $passwordFingerprint = hash_hmac('sha256', 'secret', (string) config('app.key'));
        $identity = serialize(['http://127.0.0.1', $port, 'duckie', $passwordFingerprint]);

        return 'torrent:qbittorrent:sid:'.hash('sha256', $identity);
    }

    /** @return array<int, array<string, mixed>> */
    private function requests(): array
    {
        return array_values(array_filter(array_map(
            fn (string $line): ?array => $line === '' ? null : json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($this->logPath, FILE_IGNORE_NEW_LINES) ?: []
        )));
    }

    private function reserveLocalPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertNotFalse($socket, "Unable to reserve local port: {$errorCode} {$errorMessage}");

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        $this->assertIsString($name);
        $port = (int) substr(strrchr($name, ':'), 1);
        $this->assertGreaterThan(0, $port);

        return $port;
    }

    private function waitForServer(int $port): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.05);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(20_000);
        }

        $this->fail(
            'qBittorrent HTTP emulator did not start. '.
            $this->server?->getErrorOutput()
        );
    }
}
