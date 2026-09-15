<?php

namespace Tests\Feature\Controllers;

use App\Models\Episode;
use App\Models\Season;
use App\Models\Serie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisplaySettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_settings_view_is_read_only_and_uses_historical_keys(): void
    {
        $this->assertDatabaseCount('settings', 0);

        $this->get(route('settings.show', 'display'))
            ->assertOk()
            ->assertSee('name="download.ratings"', false)
            ->assertSee('name="series.not-watched-eps-btn"', false)
            ->assertSee('name="library.seriesgrid"', false)
            ->assertSee('name="background-rotator.opacity"', false)
            ->assertSee('name="font.bebas.enabled"', false)
            ->assertSee('name="kc.always"', false)
            ->assertDontSee('display.show_ratings')
            ->assertDontSee('display.not_watched_eps_btn')
            ->assertDontSee('display.transitions')
            ->assertDontSee('display.bg_opacity')
            ->assertDontSee('display.mixed_case')
            ->assertDontSee('display.top_sites')
            ->assertDontSee('display.has_top_sites')
            ->assertDontSee('display.has_notifications')
            ->assertDontSee('name="notifications.enabled"', false);

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_display_settings_persist_only_canonical_historical_keys(): void
    {
        $this->postJson(route('settings.update', 'display'), [
            'download.ratings' => false,
            'series.not-watched-eps-btn' => true,
            'library.seriesgrid' => false,
            'background-rotator.opacity' => 0.65,
            'font.bebas.enabled' => false,
            'kc.always' => true,
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertFalse((bool) settings()->get('download.ratings'));
        $this->assertTrue((bool) settings()->get('series.not-watched-eps-btn'));
        $this->assertFalse((bool) settings()->get('library.seriesgrid'));
        $this->assertSame(0.65, (float) settings()->get('background-rotator.opacity'));
        $this->assertFalse((bool) settings()->get('font.bebas.enabled'));
        $this->assertTrue((bool) settings()->get('kc.always'));

        foreach ([
            'display.show_ratings',
            'display.not_watched_eps_btn',
            'display.transitions',
            'display.bg_opacity',
            'display.mixed_case',
            'display.top_sites',
            'display.top_sites_mode',
        ] as $legacyAlias) {
            $this->assertDatabaseMissing('settings', ['key' => $legacyAlias]);
        }
    }

    public function test_noncanonical_display_aliases_are_not_persisted(): void
    {
        $this->postJson(route('settings.update', 'display'), [
            'display.show_ratings' => false,
            'display.transitions' => false,
            'display.bg_opacity' => 0.9,
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_series_library_uses_historical_seriesgrid_preference(): void
    {
        Serie::create([
            'name' => 'Display Contract Show',
            'trakt_id' => 930001,
        ]);

        settings('library.seriesgrid', true);
        $this->get(route('series.index'))
            ->assertOk()
            ->assertSee('series-grid="true"', false);

        settings('library.seriesgrid', false);
        $this->get(route('series.index'))
            ->assertOk()
            ->assertSee('series-grid="false"', false);
    }

    public function test_episode_default_season_follows_historical_preference_and_explicit_selection_wins(): void
    {
        [$serie, $firstSeason, $activeSeason] = $this->createSeasonChoiceFixture();

        settings('series.not-watched-eps-btn', false);
        $this->get(route('series.episodes', $serie->id))
            ->assertOk()
            ->assertViewHas('activeSeason', fn (Season $season): bool => $season->is($activeSeason));

        settings('series.not-watched-eps-btn', true);
        $this->get(route('series.episodes', $serie->id))
            ->assertOk()
            ->assertViewHas('activeSeason', fn (Season $season): bool => $season->is($firstSeason));

        $this->get(route('series.episodes', [
            'id' => $serie->id,
            'season_id' => $activeSeason->id,
        ]))
            ->assertOk()
            ->assertViewHas('activeSeason', fn (Season $season): bool => $season->is($activeSeason));
    }

    public function test_ratings_visibility_follows_download_ratings_setting(): void
    {
        $serie = Serie::create([
            'name' => 'Rated Show',
            'trakt_id' => 930010,
            'rating' => 87,
            'ratingcount' => 321,
        ]);

        settings('download.ratings', true);
        $this->get(route('series.details', $serie->id))
            ->assertOk()
            ->assertSee('87% (321 votes)');

        settings('download.ratings', false);
        $this->get(route('series.details', $serie->id))
            ->assertOk()
            ->assertDontSee('87% (321 votes)');
    }

    public function test_layout_applies_background_opacity_mixed_case_and_series_grid_runtime(): void
    {
        settings('background-rotator.opacity', 0.65);
        settings('font.bebas.enabled', false);
        settings('kc.always', true);

        $this->get(route('calendar.index'))
            ->assertOk()
            ->assertSee('style="opacity: 0.65"', false)
            ->assertSee('id="bebas-override"', false)
            ->assertSee('class="kc standalone"', false)
            ->assertSee('js/SeriesGrid.js', false);

        $script = file_get_contents(public_path('js/SeriesGrid.js'));
        if ($script === false) {
            $this->fail('SeriesGrid.js could not be read.');
        }

        $this->assertStringContainsString('translate3d(', $script);
        $this->assertStringContainsString('seriesgrid:recalculate', $script);
    }

    /**
     * @return array{0: Serie, 1: Season, 2: Season}
     */
    private function createSeasonChoiceFixture(): array
    {
        $serie = Serie::create([
            'name' => 'Season Choice Show',
            'trakt_id' => 930020,
        ]);

        $firstSeason = Season::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 1,
            'trakt_id' => 930021,
        ]);

        $activeSeason = Season::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 2,
            'trakt_id' => 930022,
        ]);

        Episode::create([
            'serie_id' => $serie->id,
            'season_id' => $firstSeason->id,
            'episodename' => 'Unwatched old episode',
            'episodenumber' => 1,
            'seasonnumber' => 1,
            'firstaired' => now()->subYears(2)->getTimestampMs(),
            'trakt_id' => 930023,
            'watched' => 0,
        ]);

        Episode::create([
            'serie_id' => $serie->id,
            'season_id' => $activeSeason->id,
            'episodename' => 'Watched current episode',
            'episodenumber' => 1,
            'seasonnumber' => 2,
            'firstaired' => now()->subDay()->getTimestampMs(),
            'trakt_id' => 930024,
            'watched' => 1,
        ]);

        return [$serie, $firstSeason, $activeSeason];
    }
}
