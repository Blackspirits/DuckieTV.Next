<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TransmissionFamilySettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_transmission_family_views_render_live_runtime_backed_forms(): void
    {
        foreach ([
            'transmission' => 'transmission',
            'biglybt' => 'biglybt',
            'vuze' => 'vuze',
        ] as $section => $prefix) {
            $response = $this->get(route('settings.show', $section));

            $response->assertOk()
                ->assertSee('data-section="torrent"', false)
                ->assertSee('name="'.$prefix.'.server"', false)
                ->assertSee('name="'.$prefix.'.port"', false)
                ->assertSee('name="'.$prefix.'.path"', false)
                ->assertSee('name="'.$prefix.'.use_auth"', false)
                ->assertSee('name="'.$prefix.'.username"', false)
                ->assertSee('name="'.$prefix.'.password"', false)
                ->assertDontSee($prefix.'.progressX100', false)
                ->assertDontSee('Work in Progress', false);
        }
    }

    public function test_transmission_family_runtime_backed_settings_persist(): void
    {
        foreach (['transmission', 'biglybt', 'vuze'] as $prefix) {
            $this->postJson(route('settings.update', 'torrent'), [
                $prefix.'.server' => 'http://127.0.0.1',
                $prefix.'.port' => 19091,
                $prefix.'.path' => '/rpc',
                $prefix.'.use_auth' => true,
                $prefix.'.username' => 'duckie',
                $prefix.'.password' => 'secret',
            ])->assertOk()->assertJson(['success' => true]);

            $this->assertSame('http://127.0.0.1', settings()->get($prefix.'.server'));
            $this->assertSame(19091, (int) settings()->get($prefix.'.port'));
            $this->assertSame('/rpc', settings()->get($prefix.'.path'));
            $this->assertTrue((bool) settings()->get($prefix.'.use_auth'));
            $this->assertSame('duckie', settings()->get($prefix.'.username'));
            $this->assertSame('secret', settings()->get($prefix.'.password'));
        }
    }

    public function test_connection_test_response_echoes_only_submitted_transport_coordinates(): void
    {
        settings('torrenting.client', 'Transmission');

        Http::fake([
            'http://127.0.0.1:19091/rpc' => Http::response([
                'result' => 'success',
                'arguments' => [],
            ]),
        ]);

        $response = $this->postJson(route('settings.update', 'torrent'), [
            'test' => 1,
            'transmission.server' => 'http://127.0.0.1',
            'transmission.port' => 19091,
            'transmission.path' => '/rpc',
            'transmission.use_auth' => true,
            'transmission.username' => 'duckie',
            'transmission.password' => 'top-secret',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'connection_success' => true,
                'server' => 'http://127.0.0.1',
                'port' => 19091,
            ]);

        $this->assertStringNotContainsString('top-secret', $response->getContent());
    }

    public function test_transmission_family_ports_are_bounded(): void
    {
        settings('transmission.port', 9091);

        foreach ([0, 65536] as $port) {
            $this->postJson(route('settings.update', 'torrent'), [
                'transmission.port' => $port,
            ])->assertUnprocessable();
        }

        $this->assertSame(9091, (int) settings()->get('transmission.port'));
    }

    public function test_unknown_dead_progress_key_cannot_toggle_authentication(): void
    {
        settings('transmission.use_auth', true);
        settings('transmission.progressX100', true);

        $this->postJson(route('settings.update', 'torrent'), [
            'transmission.progressX100' => false,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertTrue((bool) settings()->get('transmission.use_auth'));
        $this->assertTrue((bool) settings()->get('transmission.progressX100'));
    }

    public function test_dead_progress_flags_cannot_be_mutated(): void
    {
        foreach (['transmission', 'biglybt', 'vuze'] as $prefix) {
            settings($prefix.'.progressX100', true);

            $this->postJson(route('settings.update', 'torrent'), [
                $prefix.'.progressX100' => false,
            ])->assertOk()->assertJson(['success' => true]);

            $this->assertTrue((bool) settings()->get($prefix.'.progressX100'));
        }
    }
}
