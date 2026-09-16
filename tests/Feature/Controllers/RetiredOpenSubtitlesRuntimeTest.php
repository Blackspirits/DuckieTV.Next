<?php

namespace Tests\Feature\Controllers;

use App\Models\Episode;
use App\Models\Serie;
use App\Services\SubtitlesService;
use App\Services\TorrentClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RetiredOpenSubtitlesRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_opensubtitles_runtime_reports_unavailable(): void
    {
        $this->assertFalse(app(SubtitlesService::class)->isRuntimeAvailable());
    }

    public function test_retired_subtitle_routes_fail_closed_without_network_requests(): void
    {
        Http::fake();

        $this->get(route('subtitles.index'))->assertStatus(410);

        $this->postJson(route('subtitles.search-query'), [
            'query' => 'DuckieTV',
        ])->assertStatus(410);

        $this->postJson(route('subtitles.search'), [
            'episode_id' => 999,
        ])->assertStatus(410);

        Http::assertNothingSent();
    }

    public function test_active_ui_does_not_advertise_retired_subtitle_search(): void
    {
        $this->get(route('calendar.index'))
            ->assertOk()
            ->assertDontSee('id="actionbar_subtitles"', false);

        settings('torrenting.enabled', true);

        $serie = Serie::create([
            'name' => 'Subtitle Retirement Show',
            'trakt_id' => 81001,
        ]);

        $episode = Episode::create([
            'serie_id' => $serie->id,
            'episodename' => 'Retired API',
            'trakt_id' => 81002,
            'seasonnumber' => 1,
            'episodenumber' => 1,
            'firstaired' => now()->subDay()->getTimestampMs(),
        ]);

        $torrentClients = Mockery::mock(TorrentClientService::class);
        $torrentClients->shouldReceive('getActiveClient')->andReturnNull();
        $this->app->instance(TorrentClientService::class, $torrentClients);

        $this->get(route('episodes.show', $episode->id))
            ->assertOk()
            ->assertDontSee('Subtitles.search(', false);
    }
}
