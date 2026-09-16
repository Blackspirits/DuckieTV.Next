<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TorrentClientServerContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_active_torrent_clients_require_origin_only_server_values(): void
    {
        $prefixes = [
            'aria2',
            'biglybt',
            'deluge',
            'ktorrent',
            'qbittorrent32plus',
            'rtorrent',
            'tixati',
            'transmission',
            'ttorrent',
            'utorrentwebui',
            'vuze',
        ];

        foreach ($prefixes as $prefix) {
            $this->postJson(route('settings.update', 'torrent'), [
                $prefix.'.server' => 'https://torrent.example/',
            ])->assertOk()->assertJson(['success' => true]);

            $this->assertSame('https://torrent.example/', settings()->get($prefix.'.server'));

            $this->postJson(route('settings.update', 'torrent'), [
                $prefix.'.server' => 'https://torrent.example:8443/path?query=1',
            ])->assertUnprocessable();

            $this->assertSame('https://torrent.example/', settings()->get($prefix.'.server'));
        }
    }
}
