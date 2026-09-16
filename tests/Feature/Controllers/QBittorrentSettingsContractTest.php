<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QBittorrentSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_qbittorrent_view_exposes_only_runtime_backed_fields(): void
    {
        $this->get(route('settings.show', 'qbittorrent41plus'))
            ->assertOk()
            ->assertSee('name="qbittorrent32plus.server"', false)
            ->assertSee('name="qbittorrent32plus.port"', false)
            ->assertSee('min="1"', false)
            ->assertSee('max="65535"', false)
            ->assertSee('name="qbittorrent32plus.username"', false)
            ->assertSee('name="qbittorrent32plus.password"', false)
            ->assertDontSee('qbittorrent32plus.use_auth', false);
    }

    public function test_qbittorrent_runtime_backed_settings_persist_and_ports_are_bounded(): void
    {
        $this->postJson(route('settings.update', 'torrent'), [
            'qbittorrent32plus.server' => 'http://127.0.0.1',
            'qbittorrent32plus.port' => 18080,
            'qbittorrent32plus.username' => 'duckie',
            'qbittorrent32plus.password' => 'secret',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('http://127.0.0.1', settings()->get('qbittorrent32plus.server'));
        $this->assertSame(18080, (int) settings()->get('qbittorrent32plus.port'));
        $this->assertSame('duckie', settings()->get('qbittorrent32plus.username'));
        $this->assertSame('secret', settings()->get('qbittorrent32plus.password'));

        foreach ([0, 65536] as $port) {
            $this->postJson(route('settings.update', 'torrent'), [
                'qbittorrent32plus.port' => $port,
            ])->assertUnprocessable();
        }

        $this->assertSame(18080, (int) settings()->get('qbittorrent32plus.port'));
    }

    public function test_dead_qbittorrent_auth_flag_cannot_be_mutated(): void
    {
        settings('qbittorrent32plus.use_auth', true);

        $this->postJson(route('settings.update', 'torrent'), [
            'qbittorrent32plus.use_auth' => false,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertTrue((bool) settings()->get('qbittorrent32plus.use_auth'));
    }
}
