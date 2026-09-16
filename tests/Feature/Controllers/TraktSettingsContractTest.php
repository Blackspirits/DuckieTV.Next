<?php

namespace Tests\Feature\Controllers;

use App\Services\SettingsService;
use App\Services\TraktUpdateLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TraktSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_trakt_update_period_default_is_twelve_hours(): void
    {
        $this->assertSame(12, (int) app(SettingsService::class)->get('trakt-update.period'));
    }

    public function test_default_twelve_hour_cadence_is_used_by_periodic_lifecycle(): void
    {
        settings('trakttv.lastupdated', now()->subHours(2)->getTimestampMs());
        settings('trakttv.lastupdated.trending', now()->getTimestampMs());

        $this->assertFalse(app(TraktUpdateLifecycleService::class)->dispatchIfDue());
    }

    public function test_trakt_settings_render_only_runtime_backed_update_period(): void
    {
        settings('trakt-update.period', 6);
        settings('trakttv.sync', true);
        settings('trakttv.sync-downloaded', true);
        settings('trakttv.token', 'secret-token');

        $this->get(route('settings.show', 'trakttv'))
            ->assertOk()
            ->assertSee('trakt-update.period', false)
            ->assertSee('value="6"', false)
            ->assertDontSee('trakttv.sync', false)
            ->assertDontSee('trakttv.sync-downloaded', false)
            ->assertDontSee('trakttv.token', false)
            ->assertDontSee('OAuth flow not implemented', false)
            ->assertDontSee('Toggle sync downloaded not implemented', false);
    }

    public function test_trakt_update_period_accepts_only_one_to_twenty_four_hours(): void
    {
        foreach ([0, 25, 1.5] as $period) {
            $this->postJson(route('settings.update', 'trakttv'), [
                'trakt-update.period' => $period,
            ])->assertUnprocessable();
        }

        $this->postJson(route('settings.update', 'trakttv'), [
            'trakt-update.period' => 24,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(24, (int) settings()->get('trakt-update.period'));
    }

    public function test_unimplemented_trakt_sync_fields_cannot_be_mutated_through_settings_endpoint(): void
    {
        settings('trakttv.sync', false);
        settings('trakttv.sync-downloaded', true);
        settings('trakttv.username', 'existing-user');
        settings('trakttv.passwordHash', 'existing-hash');

        $this->postJson(route('settings.update', 'trakttv'), [
            'trakttv.sync' => true,
            'trakttv.sync-downloaded' => false,
            'trakttv.username' => 'attacker-user',
            'trakttv.passwordHash' => 'replacement-hash',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertFalse((bool) settings()->get('trakttv.sync'));
        $this->assertTrue((bool) settings()->get('trakttv.sync-downloaded'));
        $this->assertSame('existing-user', settings()->get('trakttv.username'));
        $this->assertSame('existing-hash', settings()->get('trakttv.passwordHash'));
    }
}
