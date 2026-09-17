<?php

namespace Tests\Feature\Services;

use App\Services\SettingsService;
use App\Services\TorrentClients\TransmissionClient;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TransmissionHttpRuntimeTest extends TestCase
{
    private ?Process $server = null;

    private ?string $logPath = null;

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

    public function test_real_http_transport_preserves_numeric_status_and_negotiates_session(): void
    {
        $port = $this->reserveLocalPort();
        $this->logPath = tempnam(sys_get_temp_dir(), 'duckietv-transmission-log-');
        $this->assertNotFalse($this->logPath);

        $router = base_path('tests/Fixtures/TransmissionRpc/router.php');
        $this->server = new Process([
            PHP_BINARY,
            '-S',
            '127.0.0.1:'.$port,
            $router,
        ], base_path(), [
            'TRANSMISSION_EMULATOR_LOG' => $this->logPath,
        ]);
        $this->server->start();

        $this->waitForServer($port);

        $settings = Mockery::mock(SettingsService::class);
        $values = [
            'transmission.server' => 'http://127.0.0.1',
            'transmission.port' => $port,
            'transmission.path' => '/transmission/rpc',
            'transmission.username' => '',
            'transmission.password' => '',
            'transmission.use_auth' => false,
        ];
        $settings->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturnUsing(
                fn (string $key, mixed $default = null): mixed => array_key_exists($key, $values)
                    ? $values[$key]
                    : $default
            );

        $client = new TransmissionClient($settings);

        $this->assertTrue($client->connect());
        $this->assertTrue($client->isConnected());

        $torrents = $client->getTorrents();

        $this->assertCount(1, $torrents);
        $this->assertSame('00112233445566778899AABBCCDDEEFF00112233', $torrents[0]->infoHash);
        $this->assertSame('Runtime Evidence', $torrents[0]->name);
        $this->assertSame(50.0, $torrents[0]->progress);
        $this->assertSame(4, $torrents[0]->status);
        $this->assertTrue($torrents[0]->isStarted());

        $magnet = 'magnet:?xt=urn:btih:00112233445566778899aabbccddeeff00112233';
        $this->assertTrue($client->addMagnet($magnet));

        $requests = array_values(array_filter(array_map(
            fn (string $line): ?array => $line === '' ? null : json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($this->logPath, FILE_IGNORE_NEW_LINES) ?: []
        )));

        $this->assertCount(4, $requests);
        $this->assertSame('session-get', $requests[0]['method']);
        $this->assertSame('', $requests[0]['session']);
        $this->assertSame('session-get', $requests[1]['method']);
        $this->assertSame('duckietv-test-session', $requests[1]['session']);
        $this->assertSame('torrent-get', $requests[2]['method']);
        $this->assertSame('duckietv-test-session', $requests[2]['session']);
        $this->assertSame('torrent-add', $requests[3]['method']);
        $this->assertSame('duckietv-test-session', $requests[3]['session']);
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
            'Transmission HTTP emulator did not start. '.
            $this->server?->getErrorOutput()
        );
    }
}
