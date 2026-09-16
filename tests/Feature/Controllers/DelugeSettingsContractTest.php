<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DelugeSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_deluge_view_exposes_only_runtime_backed_fields(): void
    {
        $this->get(route('settings.show', 'deluge'))
            ->assertOk()
            ->assertSee('name="deluge.server"', false)
            ->assertSee('name="deluge.port"', false)
            ->assertSee('min="1"', false)
            ->assertSee('max="65535"', false)
            ->assertSee('name="deluge.password"', false)
            ->assertDontSee('deluge.use_auth', false);
    }

    public function test_deluge_runtime_backed_settings_persist_and_ports_are_bounded(): void
    {
        $this->postJson(route('settings.update', 'torrent'), [
            'deluge.server' => 'http://127.0.0.1',
            'deluge.port' => 18112,
            'deluge.password' => 'secret',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('http://127.0.0.1', settings()->get('deluge.server'));
        $this->assertSame(18112, (int) settings()->get('deluge.port'));
        $this->assertSame('secret', settings()->get('deluge.password'));

        foreach ([0, 65536] as $port) {
            $this->postJson(route('settings.update', 'torrent'), [
                'deluge.port' => $port,
            ])->assertUnprocessable();
        }

        $this->assertSame(18112, (int) settings()->get('deluge.port'));
    }

    public function test_dead_deluge_auth_flag_cannot_be_mutated(): void
    {
        settings('deluge.use_auth', true);

        $this->postJson(route('settings.update', 'torrent'), [
            'deluge.use_auth' => false,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertTrue((bool) settings()->get('deluge.use_auth'));
    }
}
