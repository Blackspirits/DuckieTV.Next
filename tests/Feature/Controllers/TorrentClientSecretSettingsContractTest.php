<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TorrentClientSecretSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function secretSections(): array
    {
        return [
            'aria2' => 'aria2.token',
            'biglybt' => 'biglybt.password',
            'deluge' => 'deluge.password',
            'ktorrent' => 'ktorrent.password',
            'qbittorrent41plus' => 'qbittorrent32plus.password',
            'tixati' => 'tixati.password',
            'transmission' => 'transmission.password',
            'ttorrent' => 'ttorrent.password',
            'utorrentwebui' => 'utorrentwebui.password',
            'vuze' => 'vuze.password',
        ];
    }

    public function test_torrent_client_secrets_are_never_rendered_back_into_settings_html(): void
    {
        foreach ($this->secretSections() as $section => $key) {
            $secret = 'html-secret-'.$section;
            settings($key, $secret);

            $this->get(route('settings.show', $section))
                ->assertOk()
                ->assertSee('name="'.$key.'"', false)
                ->assertSee('autocomplete="new-password"', false)
                ->assertDontSee($secret, false);
        }
    }

    public function test_blank_or_null_secret_submission_preserves_every_existing_secret(): void
    {
        foreach ($this->secretSections() as $section => $key) {
            $secret = 'preserve-secret-'.$section;
            settings($key, $secret);

            foreach (['', null] as $blank) {
                $this->postJson(route('settings.update', 'torrent'), [
                    $key => $blank,
                ])->assertOk()->assertJson(['success' => true]);

                $this->assertSame($secret, settings()->get($key));
            }
        }
    }

    public function test_non_empty_secret_submission_replaces_saved_secret(): void
    {
        foreach ($this->secretSections() as $section => $key) {
            settings($key, 'old-secret-'.$section);

            $replacement = 'new-secret-'.$section;
            $this->postJson(route('settings.update', 'torrent'), [
                $key => $replacement,
            ])->assertOk()->assertJson(['success' => true]);

            $this->assertSame($replacement, settings()->get($key));
        }
    }

    public function test_blank_secret_alone_does_not_disable_existing_authentication(): void
    {
        foreach (['biglybt', 'tixati', 'transmission', 'ttorrent', 'utorrentwebui', 'vuze'] as $prefix) {
            settings($prefix.'.use_auth', true);
            settings($prefix.'.password', 'preserved-'.$prefix);

            $this->postJson(route('settings.update', 'torrent'), [
                $prefix.'.password' => '',
            ])->assertOk()->assertJson(['success' => true]);

            $this->assertTrue((bool) settings()->get($prefix.'.use_auth'));
            $this->assertSame('preserved-'.$prefix, settings()->get($prefix.'.password'));
        }
    }

    public function test_disabling_authentication_with_blank_password_preserves_saved_secret(): void
    {
        foreach (['biglybt', 'tixati', 'transmission', 'ttorrent', 'utorrentwebui', 'vuze'] as $prefix) {
            settings($prefix.'.use_auth', true);
            settings($prefix.'.password', 'preserved-'.$prefix);

            $this->postJson(route('settings.update', 'torrent'), [
                $prefix.'.use_auth' => false,
                $prefix.'.password' => '',
            ])->assertOk()->assertJson(['success' => true]);

            $this->assertFalse((bool) settings()->get($prefix.'.use_auth'));
            $this->assertSame('preserved-'.$prefix, settings()->get($prefix.'.password'));
        }
    }

    public function test_historical_unsafe_server_values_are_never_rendered_back_into_settings_html(): void
    {
        $contracts = [
            'aria2' => ['aria2.server', 'http://user:aria2-server-secret@127.0.0.1'],
            'transmission' => ['transmission.server', 'http://127.0.0.1/path/transmission-server-secret'],
            'deluge' => ['deluge.server', 'http://127.0.0.1?token=deluge-server-secret'],
            'qbittorrent41plus' => ['qbittorrent32plus.server', 'http://127.0.0.1#qbittorrent-server-secret'],
        ];

        foreach ($contracts as $section => [$key, $server]) {
            settings($key, $server);

            $this->get(route('settings.show', $section))
                ->assertOk()
                ->assertSee('name="'.$key.'"', false)
                ->assertDontSee($server, false)
                ->assertDontSee('server-secret', false);
        }
    }

    public function test_connection_test_with_blank_password_uses_persisted_transmission_secret(): void
    {
        settings('torrenting.client', 'Transmission');
        settings('transmission.server', 'http://127.0.0.1');
        settings('transmission.port', 19091);
        settings('transmission.path', '/rpc');
        settings('transmission.use_auth', true);
        settings('transmission.username', 'duckie');
        settings('transmission.password', 'persisted-secret');

        Http::fake([
            'http://127.0.0.1:19091/rpc' => Http::response([
                'result' => 'success',
                'arguments' => [],
            ]),
        ]);

        $this->postJson(route('settings.update', 'torrent'), [
            'test' => 1,
            'transmission.server' => 'http://127.0.0.1',
            'transmission.port' => 19091,
            'transmission.path' => '/rpc',
            'transmission.use_auth' => true,
            'transmission.username' => 'duckie',
            'transmission.password' => '',
        ])->assertOk()
            ->assertJson([
                'success' => true,
                'connection_success' => true,
            ]);

        $this->assertSame('persisted-secret', settings()->get('transmission.password'));

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader(
                'Authorization',
                'Basic '.base64_encode('duckie:persisted-secret')
            );
        });
    }
}
