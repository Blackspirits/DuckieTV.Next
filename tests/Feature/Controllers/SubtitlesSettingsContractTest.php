<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubtitlesSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_subtitle_settings_render_uses_opensubtitles_codes_without_writing(): void
    {
        settings('subtitles.languages', ['por']);
        $before = \App\Models\Setting::query()->count();

        $this->get(route('settings.show', 'subtitles'))
            ->assertOk()
            ->assertSee("toggleSubtitleLanguage('por')", false)
            ->assertSee('data-subtitle-code="eng"', false)
            ->assertSee('flag-pt', false)
            ->assertDontSee('not implemented');

        $this->assertSame($before, \App\Models\Setting::query()->count());
        $this->assertSame(['por'], settings()->get('subtitles.languages'));
    }

    public function test_subtitle_settings_accept_multiple_supported_language_codes(): void
    {
        $this->postJson(route('settings.update', 'subtitles'), [
            'subtitles.languages' => ['por', 'eng'],
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(['por', 'eng'], settings()->get('subtitles.languages'));
    }

    public function test_subtitle_settings_clear_selection_persists_empty_array(): void
    {
        settings('subtitles.languages', ['eng']);

        $this->postJson(route('settings.update', 'subtitles'), [
            'subtitles.languages' => [],
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame([], settings()->get('subtitles.languages'));
    }

    public function test_subtitle_settings_reject_ui_locale_codes_without_writing(): void
    {
        $this->postJson(route('settings.update', 'subtitles'), [
            'subtitles.languages' => ['pt_PT'],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('settings', 0);
    }
}
