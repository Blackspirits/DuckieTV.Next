<?php

namespace Tests\Feature\Controllers;

use App\Models\Episode;
use App\Models\Season;
use App\Models\Serie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchedDownloadedPairingTest extends TestCase
{
    use RefreshDatabase;

    public function test_miscellaneous_settings_view_uses_historical_pairing_contract(): void
    {
        settings('episode.watched-downloaded.pairing', true);

        $this->get(route('settings.show', 'miscellaneous'))
            ->assertOk()
            ->assertSee('name="episode.watched-downloaded.pairing"', false)
            ->assertSee("Settings.save('miscellaneous')", false)
            ->assertSee(__('SETTINGS/MISCELLANEOUS/watchedDownloadedPaired/hdr'))
            ->assertDontSee('Toggle setting not implemented')
            ->assertDontSee('miscellaneous.watched_downloaded_paired');
    }

    public function test_miscellaneous_pairing_setting_can_be_persisted_and_validated(): void
    {
        $this->postJson(route('settings.update', 'miscellaneous'), [
            'episode.watched-downloaded.pairing' => false,
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertFalse((bool) settings()->get('episode.watched-downloaded.pairing'));

        $this->postJson(route('settings.update', 'miscellaneous'), [
            'episode.watched-downloaded.pairing' => 'not-a-boolean',
        ])->assertStatus(422);
    }

    public function test_episode_watched_toggle_does_not_force_downloaded_when_pairing_is_disabled(): void
    {
        settings('episode.watched-downloaded.pairing', false);
        [, $episode] = $this->createAiredEpisode([
            'watched' => 0,
            'downloaded' => 0,
        ]);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->patchJson(route('episodes.update', $episode->id), [
                'action' => 'toggle_watched',
            ])
            ->assertOk();

        $episode->refresh();

        $this->assertSame(1, (int) $episode->watched);
        $this->assertSame(0, (int) $episode->downloaded);
        $this->assertNotNull($episode->watchedAt);
    }

    public function test_episode_not_downloaded_toggle_preserves_watched_state_when_pairing_is_disabled(): void
    {
        settings('episode.watched-downloaded.pairing', false);
        $watchedAt = now()->subHour()->getTimestampMs();
        [, $episode] = $this->createAiredEpisode([
            'watched' => 1,
            'watchedAt' => $watchedAt,
            'downloaded' => 1,
            'magnetHash' => '0123456789abcdef0123456789abcdef01234567',
        ]);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->patchJson(route('episodes.update', $episode->id), [
                'action' => 'toggle_download',
            ])
            ->assertOk();

        $episode->refresh();

        $this->assertSame(0, (int) $episode->downloaded);
        $this->assertSame(1, (int) $episode->watched);
        $this->assertSame($watchedAt, (int) $episode->watchedAt);
        $this->assertSame('0123456789abcdef0123456789abcdef01234567', $episode->magnetHash);
    }

    public function test_episode_not_downloaded_toggle_clears_watched_state_when_pairing_is_enabled(): void
    {
        settings('episode.watched-downloaded.pairing', true);
        [, $episode] = $this->createAiredEpisode([
            'watched' => 1,
            'watchedAt' => now()->subHour()->getTimestampMs(),
            'downloaded' => 1,
            'magnetHash' => '0123456789abcdef0123456789abcdef01234567',
        ]);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->patchJson(route('episodes.update', $episode->id), [
                'action' => 'toggle_download',
            ])
            ->assertOk();

        $episode->refresh();

        $this->assertSame(0, (int) $episode->downloaded);
        $this->assertSame(0, (int) $episode->watched);
        $this->assertNull($episode->watchedAt);
        $this->assertNull($episode->magnetHash);
    }

    public function test_series_mark_watched_respects_disabled_pairing(): void
    {
        settings('episode.watched-downloaded.pairing', false);
        [$serie, $episode] = $this->createAiredEpisode([
            'watched' => 0,
            'downloaded' => 0,
        ]);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->patchJson(route('series.update', $serie->id), [
                'action' => 'mark_watched',
            ])
            ->assertOk();

        $episode->refresh();

        $this->assertSame(1, (int) $episode->watched);
        $this->assertSame(0, (int) $episode->downloaded);
    }

    public function test_season_mark_watched_respects_disabled_pairing(): void
    {
        settings('episode.watched-downloaded.pairing', false);
        [$serie, $episode, $season] = $this->createAiredEpisode([
            'watched' => 0,
            'downloaded' => 0,
        ]);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->patchJson(route('series.update', $serie->id), [
                'action' => 'mark_season_watched',
                'season_id' => $season->id,
            ])
            ->assertOk();

        $episode->refresh();

        $this->assertSame(1, (int) $episode->watched);
        $this->assertSame(0, (int) $episode->downloaded);
    }

    public function test_calendar_mark_day_watched_respects_disabled_pairing(): void
    {
        settings('episode.watched-downloaded.pairing', false);
        [, $episode] = $this->createAiredEpisode(
            ['watched' => 0, 'downloaded' => 0],
            now()->subDay()->startOfDay()->addHours(20)->getTimestampMs()
        );

        $this->post(route('calendar.mark-watched'), [
            'date' => now()->subDay()->toDateString(),
        ])->assertRedirect();

        $episode->refresh();

        $this->assertSame(1, (int) $episode->watched);
        $this->assertSame(0, (int) $episode->downloaded);
    }

    /**
     * @return array{0: Serie, 1: Episode, 2: Season}
     */
    private function createAiredEpisode(array $overrides = [], ?int $firstAired = null): array
    {
        $serie = Serie::create([
            'name' => 'Pairing Contract',
            'trakt_id' => 910001,
            'displaycalendar' => true,
        ]);

        $season = Season::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 1,
            'trakt_id' => 910002,
        ]);

        $episode = Episode::create(array_merge([
            'serie_id' => $serie->id,
            'season_id' => $season->id,
            'episodename' => 'Pairing Episode',
            'episodenumber' => 1,
            'seasonnumber' => 1,
            'firstaired' => $firstAired ?? now()->subDay()->getTimestampMs(),
            'firstaired_iso' => now()->subDay()->toIso8601String(),
            'trakt_id' => 910003,
            'watched' => 0,
            'downloaded' => 0,
        ], $overrides));

        return [$serie, $episode, $season];
    }
}
