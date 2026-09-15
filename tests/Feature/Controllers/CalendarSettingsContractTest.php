<?php

namespace Tests\Feature\Controllers;

use App\Models\Episode;
use App\Models\Season;
use App\Models\Serie;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_settings_view_is_read_only_and_uses_historical_keys(): void
    {
        $this->assertDatabaseCount('settings', 0);

        $this->get(route('settings.show', 'calendar'))
            ->assertOk()
            ->assertSee('name="calendar.startSunday"', false)
            ->assertSee('name="calendar.mode"', false)
            ->assertSee('name="calendar.show-specials"', false)
            ->assertSee('name="calendar.show-downloaded"', false)
            ->assertSee('name="calendar.show-episode-numbers"', false)
            ->assertDontSee('calendar.start_sunday')
            ->assertDontSee('calendar.show_specials')
            ->assertDontSee('calendar.show_downloaded')
            ->assertDontSee('calendar.show_episode_numbers')
            ->assertDontSee('not implemented');

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_calendar_settings_persist_historical_keys(): void
    {
        $this->postJson(route('settings.update', 'calendar'), [
            'calendar.startSunday' => false,
            'calendar.mode' => 'week',
            'calendar.show-specials' => false,
            'calendar.show-downloaded' => false,
            'calendar.show-episode-numbers' => true,
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertFalse((bool) settings()->get('calendar.startSunday'));
        $this->assertSame('week', settings()->get('calendar.mode'));
        $this->assertFalse((bool) settings()->get('calendar.show-specials'));
        $this->assertFalse((bool) settings()->get('calendar.show-downloaded'));
        $this->assertTrue((bool) settings()->get('calendar.show-episode-numbers'));

        $this->assertDatabaseMissing('settings', ['key' => 'calendar.start_sunday']);
        $this->assertDatabaseMissing('settings', ['key' => 'calendar.show_specials']);
        $this->assertDatabaseMissing('settings', ['key' => 'calendar.show_downloaded']);
        $this->assertDatabaseMissing('settings', ['key' => 'calendar.show_episode_numbers']);
    }

    public function test_saved_calendar_mode_is_used_when_url_has_no_mode_override(): void
    {
        settings('calendar.mode', 'week');

        $this->get(route('calendar.index', ['date' => '2026-09-15']))
            ->assertOk()
            ->assertViewHas('mode', 'week')
            ->assertViewHas('calendarState', fn (array $state): bool => $state['mode'] === 'week');

        settings('calendar.mode', 'date');

        $this->get(route('calendar.index', ['date' => '2026-09-15']))
            ->assertOk()
            ->assertViewHas('mode', 'month')
            ->assertViewHas('calendarState', fn (array $state): bool => $state['mode'] === 'month');
    }

    public function test_explicit_calendar_mode_overrides_saved_default(): void
    {
        settings('calendar.mode', 'week');

        $this->get(route('calendar.index', [
            'date' => '2026-09-15',
            'mode' => 'year',
        ]))
            ->assertOk()
            ->assertViewHas('mode', 'year');
    }

    public function test_calendar_boundaries_follow_historical_start_sunday_setting(): void
    {
        settings('calendar.startSunday', true);

        $this->get(route('calendar.index', [
            'date' => '2026-09-15',
            'mode' => 'month',
        ]))
            ->assertOk()
            ->assertViewHas('start', fn (Carbon $start): bool => $start->dayOfWeek === Carbon::SUNDAY)
            ->assertViewHas('end', fn (Carbon $end): bool => $end->dayOfWeek === Carbon::SATURDAY);

        settings('calendar.startSunday', false);

        $this->get(route('calendar.index', [
            'date' => '2026-09-15',
            'mode' => 'month',
        ]))
            ->assertOk()
            ->assertViewHas('start', fn (Carbon $start): bool => $start->dayOfWeek === Carbon::MONDAY)
            ->assertViewHas('end', fn (Carbon $end): bool => $end->dayOfWeek === Carbon::SUNDAY);
    }

    public function test_calendar_state_carries_download_and_episode_number_preferences(): void
    {
        [$serie, $season] = $this->createSerieAndSeason();
        $aired = Carbon::parse('2026-09-15 20:00:00 UTC');

        $episode = Episode::create([
            'serie_id' => $serie->id,
            'season_id' => $season->id,
            'episodename' => 'Calendar Contract Episode',
            'episodenumber' => 1,
            'seasonnumber' => 1,
            'firstaired' => $aired->getTimestampMs(),
            'firstaired_iso' => $aired->toIso8601String(),
            'trakt_id' => 920003,
            'watched' => 0,
            'downloaded' => 1,
        ]);

        settings('calendar.show-downloaded', false);
        settings('calendar.show-episode-numbers', false);

        $this->get(route('calendar.index', [
            'date' => '2026-09-15',
            'mode' => 'month',
        ]))
            ->assertOk()
            ->assertViewHas('calendarState', function (array $state) use ($episode): bool {
                return $state['showDownloaded'] === false
                    && $state['showEpisodeNumbers'] === false
                    && in_array($episode->id, $state['downloadedEpisodeIds'], true);
            });

        settings('calendar.show-downloaded', true);
        settings('calendar.show-episode-numbers', true);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('calendar.index', [
                'date' => '2026-09-15',
                'mode' => 'month',
            ]))
            ->assertOk()
            ->assertSee('data-calendar-state', false)
            ->assertSee('"showDownloaded":true', false)
            ->assertSee('"showEpisodeNumbers":true', false);
    }

    /**
     * @return array{0: Serie, 1: Season}
     */
    private function createSerieAndSeason(): array
    {
        $serie = Serie::create([
            'name' => 'Calendar Contract Show',
            'trakt_id' => 920001,
            'displaycalendar' => true,
        ]);

        $season = Season::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 1,
            'trakt_id' => 920002,
        ]);

        return [$serie, $season];
    }
}
