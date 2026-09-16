<?php

namespace Tests\Feature\Controllers;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TorrentSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_torrent_settings_render_only_runtime_backed_controls(): void
    {
        settings('torrenting.client', 'qBittorrent 4.1+');
        settings('torrenting.label', true);
        $before = Setting::query()->count();

        $this->get(route('settings.show', 'torrent'))
            ->assertOk()
            ->assertSee('torrenting.enabled', false)
            ->assertSee("setTorrentClient('qBittorrent 4.1+')", false)
            ->assertSee("setTorrentClient('uTorrent Web UI')", false)
            ->assertDontSee("setTorrentClient('uTorrent')", false)
            ->assertSee('torrenting.label', false)
            ->assertDontSee('torrenting.progress', false)
            ->assertDontSee('torrenting.autostop', false)
            ->assertDontSee('torrenting.autostop_all', false)
            ->assertDontSee('torrenting.launch_via_chromium', false)
            ->assertDontSee('torrenting.streaming', false)
            ->assertDontSee('torrenting.directory', false);

        $this->assertSame($before, Setting::query()->count());
        $this->assertTrue((bool) settings()->get('torrenting.label'));
    }

    public function test_label_control_is_hidden_for_client_without_label_support(): void
    {
        settings('torrenting.client', 'Transmission');

        $this->get(route('settings.show', 'torrent'))
            ->assertOk()
            ->assertDontSee('torrenting.label', false);
    }

    public function test_torrent_settings_accept_registered_client_and_label(): void
    {
        $this->postJson(route('settings.update', 'torrent'), [
            'torrenting.client' => 'qBittorrent 4.1+',
        ])->assertOk()->assertJson(['success' => true]);

        $this->postJson(route('settings.update', 'torrent'), [
            'torrenting.label' => true,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('qBittorrent 4.1+', settings()->get('torrenting.client'));
        $this->assertTrue((bool) settings()->get('torrenting.label'));
    }

    public function test_torrent_settings_reject_unknown_client_without_overwriting(): void
    {
        settings('torrenting.client', 'Transmission');

        $this->postJson(route('settings.update', 'torrent'), [
            'torrenting.client' => '__missing__',
        ])->assertUnprocessable();

        $this->assertSame('Transmission', settings()->get('torrenting.client'));
    }

    public function test_classic_utorrent_is_not_an_active_settings_contract(): void
    {
        settings('torrenting.client', 'Transmission');

        $this->postJson(route('settings.update', 'torrent'), [
            'torrenting.client' => 'uTorrent',
        ])->assertUnprocessable();

        $this->getJson(route('settings.show', 'utorrent'))->assertUnprocessable();

        $this->assertSame('Transmission', settings()->get('torrenting.client'));
    }

    public function test_legacy_utorrent_selection_renders_the_effective_fallback_without_mutation(): void
    {
        settings('torrenting.client', 'uTorrent');
        $before = Setting::query()->count();

        $this->get(route('settings.show', 'torrent'))
            ->assertOk()
            ->assertSee("setTorrentClient('qBittorrent 4.1+')", false)
            ->assertDontSee("setTorrentClient('uTorrent')", false);

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertSee('client-link-qbittorrent41plus', false)
            ->assertDontSee('client-link-utorrent', false);

        $this->assertSame($before, Setting::query()->count());
        $this->assertSame('uTorrent', settings()->get('torrenting.client'));
    }

    public function test_runtime_less_torrent_settings_cannot_be_mutated_through_torrent_endpoint(): void
    {
        settings('torrenting.progress', true);
        settings('torrenting.autostop', true);
        settings('torrenting.autostop_all', false);
        settings('torrenting.launch_via_chromium', false);
        settings('torrenting.streaming', false);
        settings('torrenting.directory', true);

        $this->postJson(route('settings.update', 'torrent'), [
            'torrenting.progress' => false,
            'torrenting.autostop' => false,
            'torrenting.autostop_all' => true,
            'torrenting.launch_via_chromium' => true,
            'torrenting.streaming' => true,
            'torrenting.directory' => false,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertTrue((bool) settings()->get('torrenting.progress'));
        $this->assertTrue((bool) settings()->get('torrenting.autostop'));
        $this->assertFalse((bool) settings()->get('torrenting.autostop_all'));
        $this->assertFalse((bool) settings()->get('torrenting.launch_via_chromium'));
        $this->assertFalse((bool) settings()->get('torrenting.streaming'));
        $this->assertTrue((bool) settings()->get('torrenting.directory'));
    }
}
