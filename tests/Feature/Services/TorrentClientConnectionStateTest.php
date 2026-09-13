<?php

namespace Tests\Feature\Services;

use App\Services\SettingsService;
use App\Services\TorrentClients\Aria2Client;
use App\Services\TorrentClients\DelugeClient;
use App\Services\TorrentClients\KTorrentClient;
use App\Services\TorrentClients\RTorrentClient;
use App\Services\TorrentClients\TixatiClient;
use App\Services\TorrentClients\TransmissionClient;
use App\Services\TorrentClients\TTorrentClient;
use App\Services\TorrentClients\UTorrentClient;
use App\Services\TorrentClients\UTorrentWebUIClient;
use Exception;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TorrentClientConnectionStateTest extends TestCase
{
    public function test_rpc_backed_clients_sync_successful_connect_with_is_connected(): void
    {
        $settings = $this->settings(['utorrent.token' => 'token']);

        $clients = [
            new class($settings) extends Aria2Client
            {
                protected function rpc(string $method, array $params = []): mixed
                {
                    return ['version' => '1.0'];
                }
            },
            new class($settings) extends DelugeClient
            {
                protected function rpc(string $method, array $params = []): mixed
                {
                    return true;
                }
            },
            new class($settings) extends RTorrentClient
            {
                protected function rpc(string $method, array $params = [], bool $hasBase64 = false): mixed
                {
                    return '1.0';
                }
            },
            new class($settings) extends UTorrentClient
            {
                protected function rpc(string $type, array $params): array
                {
                    return ['session' => 'session-id'];
                }
            },
        ];

        foreach ($clients as $client) {
            $this->assertFalse($client->isConnected());
            $this->assertTrue($client->connect());
            $this->assertTrue($client->isConnected());
        }
    }

    public function test_ktorrent_connect_and_remote_failure_update_connection_state(): void
    {
        Http::fakeSequence()
            ->push('<root><challenge>abc</challenge></root>', 200)
            ->push('', 200)
            ->push('', 500);

        $client = new KTorrentClient($this->settings([
            'ktorrent.server' => 'http://localhost',
            'ktorrent.port' => 8080,
            'ktorrent.username' => 'user',
            'ktorrent.password' => 'password',
        ]));

        $this->assertTrue($client->connect());
        $this->assertTrue($client->isConnected());
        $this->assertSame([], $client->getTorrents());
        $this->assertFalse($client->isConnected());
    }

    public function test_aria2_remote_list_failure_marks_client_disconnected(): void
    {
        $settings = $this->settings([
            'aria2.server' => 'http://localhost',
            'aria2.port' => 6800,
            'aria2.token' => '',
        ]);

        $client = new class($settings) extends Aria2Client
        {
            protected function rpc(string $method, array $params = []): mixed
            {
                return ['version' => '1.0'];
            }
        };

        $this->assertTrue($client->connect());
        Http::fake(fn () => Http::response('', 500));

        $this->assertSame([], $client->getTorrents());
        $this->assertFalse($client->isConnected());
    }

    public function test_rpc_backed_remote_list_failures_mark_clients_disconnected(): void
    {
        $settings = $this->settings(['utorrent.token' => 'token']);

        $deluge = new class($settings) extends DelugeClient
        {
            public bool $failList = false;

            protected function rpc(string $method, array $params = []): mixed
            {
                if ($method === 'auth.check_session') {
                    return true;
                }

                if ($this->failList) {
                    throw new Exception('offline');
                }

                return ['torrents' => []];
            }
        };

        $rtorrent = new class($settings) extends RTorrentClient
        {
            public bool $failList = false;

            protected function rpc(string $method, array $params = [], bool $hasBase64 = false): mixed
            {
                if ($method === 'system.api_version') {
                    return '1.0';
                }

                if ($this->failList) {
                    throw new Exception('offline');
                }

                return [];
            }
        };

        $utorrent = new class($settings) extends UTorrentClient
        {
            public bool $failList = false;

            protected function rpc(string $type, array $params): array
            {
                if ($type === 'state') {
                    return ['session' => 'session-id'];
                }

                if ($this->failList) {
                    throw new Exception('offline');
                }

                return ['torrents' => []];
            }
        };

        foreach ([$deluge, $rtorrent, $utorrent] as $client) {
            $this->assertTrue($client->connect());
            $client->failList = true;
            $this->assertSame([], $client->getTorrents());
            $this->assertFalse($client->isConnected());
        }
    }

    public function test_ttorrent_remote_list_failure_marks_client_disconnected(): void
    {
        Http::fakeSequence()
            ->push('<div class="header">tTorrent web interface</div>', 200)
            ->push('', 500);

        $client = new TTorrentClient($this->settings([
            'ttorrent.server' => 'http://localhost',
            'ttorrent.port' => 8080,
            'ttorrent.use_auth' => false,
        ]));

        $this->assertTrue($client->connect());
        $this->assertSame([], $client->getTorrents());
        $this->assertFalse($client->isConnected());
    }

    public function test_tixati_remote_list_failure_marks_client_disconnected(): void
    {
        Http::fakeSequence()
            ->push('', 200)
            ->push('', 500);

        $client = new TixatiClient($this->settings([
            'tixati.server' => 'http://localhost',
            'tixati.port' => 8080,
            'tixati.use_auth' => false,
        ]));

        $this->assertTrue($client->connect());
        $this->assertSame([], $client->getTorrents());
        $this->assertFalse($client->isConnected());
    }

    public function test_utorrent_webui_remote_list_failure_marks_client_disconnected(): void
    {
        Http::fakeSequence()
            ->push('<html><div id="token">token</div></html>', 200)
            ->push('', 500);

        $client = new UTorrentWebUIClient($this->settings([
            'utorrentwebui.server' => 'http://localhost',
            'utorrentwebui.port' => 8080,
            'utorrentwebui.use_auth' => false,
        ]));

        $this->assertTrue($client->connect());
        $this->assertSame([], $client->getTorrents());
        $this->assertFalse($client->isConnected());
    }

    public function test_transmission_remote_list_exception_clears_state_before_propagating(): void
    {
        $client = new class($this->settings()) extends TransmissionClient
        {
            public bool $failList = false;

            protected function rpc(string $method, array $args = [], bool $isRetry = false): array
            {
                if ($method === 'session-get') {
                    return ['result' => 'success'];
                }

                if ($this->failList) {
                    throw new Exception('offline');
                }

                return ['result' => 'success', 'arguments' => ['torrents' => []]];
            }
        };

        $this->assertTrue($client->connect());
        $client->failList = true;

        try {
            $client->getTorrents();
            $this->fail('Expected remote-list exception.');
        } catch (Exception $e) {
            $this->assertSame('offline', $e->getMessage());
        }

        $this->assertFalse($client->isConnected());
    }

    private function settings(array $values = []): SettingsService
    {
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
}
