<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LanguageSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_language_settings_view_is_read_only_and_uses_historical_locale_contract(): void
    {
        $this->assertDatabaseCount('settings', 0);

        $this->get(route('settings.show', 'language'))
            ->assertOk()
            ->assertSee('data-section="language"', false)
            ->assertSee('name="application.locale"', false)
            ->assertSee("setLanguageLocale('pt_pt')", false)
            ->assertDontSee('not implemented');

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_language_update_persists_historical_language_and_locale_pair(): void
    {
        $this->postJson(route('settings.update', 'language'), [
            'application.locale' => 'pt_pt',
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('pt_pt', settings()->get('application.locale'));
        $this->assertSame('pt_pt', settings()->get('application.language'));

        $this->get(route('calendar.index'))
            ->assertOk()
            ->assertSee('<html lang="pt-PT">', false);
    }

    public function test_historical_lowercase_default_resolves_to_case_sensitive_translation_file(): void
    {
        $this->get(route('calendar.index'))
            ->assertOk()
            ->assertSee('<html lang="en-US">', false);

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_unknown_stored_locale_falls_back_without_rewriting_setting(): void
    {
        settings('application.locale', 'zz_zz');
        $before = \App\Models\Setting::query()->count();

        $this->get(route('calendar.index'))
            ->assertOk()
            ->assertSee('<html lang="en-US">', false);

        $this->assertSame($before, \App\Models\Setting::query()->count());
        $this->assertSame('zz_zz', settings()->get('application.locale'));
    }

    public function test_imported_mixed_case_locale_is_resolved_without_rewriting_setting(): void
    {
        settings('application.locale', 'pt_PT');
        $before = \App\Models\Setting::query()->count();

        $this->get(route('calendar.index'))
            ->assertOk()
            ->assertSee('<html lang="pt-PT">', false);

        $this->assertSame($before, \App\Models\Setting::query()->count());
        $this->assertSame('pt_PT', settings()->get('application.locale'));
    }

    public function test_language_update_rejects_unknown_locale_without_writing(): void
    {
        $this->postJson(route('settings.update', 'language'), [
            'application.locale' => 'zz_zz',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('settings', 0);
    }
}
