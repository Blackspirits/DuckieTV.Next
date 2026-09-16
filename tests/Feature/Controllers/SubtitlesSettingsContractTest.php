<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubtitlesSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_subtitle_settings_surface_is_unavailable_without_mutating_saved_preferences(): void
    {
        settings('subtitles.languages', ['por']);

        $this->get(route('settings.show', 'subtitles'))
            ->assertNotFound();

        $this->postJson(route('settings.update', 'subtitles'), [
            'subtitles.languages' => ['eng'],
        ])->assertNotFound();

        $this->assertSame(['por'], settings()->get('subtitles.languages'));
    }

    public function test_settings_sidebar_does_not_advertise_retired_subtitle_integration(): void
    {
        $this->get(route('settings.index'))
            ->assertOk()
            ->assertDontSee(route('settings.show', 'subtitles'), false);
    }
}
