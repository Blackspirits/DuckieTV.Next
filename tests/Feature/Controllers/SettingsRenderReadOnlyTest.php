<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsRenderReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_settings_render_does_not_persist_defaults_or_overwrite_values(): void
    {
        settings('display.show_ratings', false);
        $before = $this->settingsRowCount();

        $this->get(route('settings.show', 'display'))->assertOk();

        $this->assertSame($before, $this->settingsRowCount());
        $this->assertFalse((bool) settings()->get('display.show_ratings'));
        $this->assertNull(
            \App\Models\Setting::query()->find('display.bg_opacity'),
            'Rendering Display settings must not persist view fallback values.'
        );
    }

    public function test_language_settings_render_preserves_selected_locale(): void
    {
        settings('application.locale', 'pt_pt');
        $before = $this->settingsRowCount();

        $this->get(route('settings.show', 'language'))->assertOk();

        $this->assertSame($before, $this->settingsRowCount());
        $this->assertSame('pt_pt', settings()->get('application.locale'));
    }

    public function test_subtitle_settings_render_preserves_selected_languages(): void
    {
        settings('subtitles.languages', ['por']);
        $before = $this->settingsRowCount();

        $this->get(route('settings.show', 'subtitles'))->assertOk();

        $this->assertSame($before, $this->settingsRowCount());
        $this->assertSame(['por'], settings()->get('subtitles.languages'));
    }

    public function test_torrent_search_render_preserves_provider_and_quality(): void
    {
        settings('torrenting.searchprovider', 'Nyaa');
        settings('torrenting.searchquality', 'FullHD');
        $before = $this->settingsRowCount();

        $this->get(route('settings.show', 'torrent-search'))->assertOk();

        $this->assertSame($before, $this->settingsRowCount());
        $this->assertSame('Nyaa', settings()->get('torrenting.searchprovider'));
        $this->assertSame('FullHD', settings()->get('torrenting.searchquality'));
    }

    public function test_trakt_settings_render_does_not_reset_legacy_placeholder_period(): void
    {
        settings('trakttv.token', 'test-token');
        settings('trakttv.update_period', 12);
        $before = $this->settingsRowCount();

        $this->get(route('settings.show', 'trakttv'))->assertOk();

        $this->assertSame($before, $this->settingsRowCount());
        $this->assertSame(12, (int) settings()->get('trakttv.update_period'));
    }

    private function settingsRowCount(): int
    {
        return \App\Models\Setting::query()->count();
    }
}
