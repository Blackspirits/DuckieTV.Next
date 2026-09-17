<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoDownloadSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_download_settings_render_only_runtime_backed_contract(): void
    {
        settings('torrenting.autodownload', true);
        settings('autodownload.period', 3);
        settings('autodownload.delay', 30);

        $this->get(route('settings.show', 'auto-download'))
            ->assertOk()
            ->assertSee('torrenting.autodownload', false)
            ->assertSee('autodownload.period', false)
            ->assertSee('autodownload.delay', false)
            ->assertDontSee('autodownload.multiSE.enabled', false)
            ->assertDontSee('autodownload.multiSE', false);
    }

    public function test_delay_cannot_exceed_submitted_lookback_window(): void
    {
        settings('autodownload.period', 7);
        settings('autodownload.delay', 15);

        $this->postJson(route('settings.update', 'auto-download'), [
            'autodownload.period' => 1,
            'autodownload.delay' => 1441,
        ])->assertUnprocessable();

        $this->assertSame(7, (int) settings()->get('autodownload.period'));
        $this->assertSame(15, (int) settings()->get('autodownload.delay'));

        $this->postJson(route('settings.update', 'auto-download'), [
            'autodownload.period' => 1,
            'autodownload.delay' => 1440,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, (int) settings()->get('autodownload.period'));
        $this->assertSame(1440, (int) settings()->get('autodownload.delay'));
    }

    public function test_partial_delay_update_uses_persisted_lookback_window(): void
    {
        settings('autodownload.period', 2);
        settings('autodownload.delay', 15);

        $this->postJson(route('settings.update', 'auto-download'), [
            'autodownload.delay' => 2881,
        ])->assertUnprocessable();

        $this->assertSame(15, (int) settings()->get('autodownload.delay'));
    }

    public function test_hidden_multi_search_engine_settings_cannot_be_mutated(): void
    {
        settings('autodownload.multiSE.enabled', true);
        settings('autodownload.multiSE', [
            'ThePirateBay' => true,
            'Nyaa' => false,
        ]);

        $this->postJson(route('settings.update', 'auto-download'), [
            'autodownload.multiSE.enabled' => false,
            'autodownload.multiSE' => [
                'ThePirateBay' => false,
                'Nyaa' => true,
            ],
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertTrue((bool) settings()->get('autodownload.multiSE.enabled'));
        $this->assertSame([
            'ThePirateBay' => true,
            'Nyaa' => false,
        ], settings()->get('autodownload.multiSE'));
    }
}
