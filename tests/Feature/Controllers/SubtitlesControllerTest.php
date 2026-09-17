<?php

namespace Tests\Feature\Controllers;

use App\Services\SubtitlesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SubtitlesControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subtitlesService = Mockery::mock(SubtitlesService::class);
        $this->subtitlesService->shouldReceive('isRuntimeAvailable')->andReturnFalse();
        $this->app->instance(SubtitlesService::class, $this->subtitlesService);
    }

    public function test_search_by_episode_fails_closed_before_service_execution(): void
    {
        $this->subtitlesService->shouldNotReceive('searchByEpisode');

        $this->post(route('subtitles.search'), [
            'episode_id' => 1,
        ])->assertStatus(410);
    }

    public function test_search_by_episode_fails_closed_before_request_validation(): void
    {
        $this->post(route('subtitles.search'), [
            'episode_id' => 9999,
        ])->assertStatus(410);
    }

    public function test_search_by_query_fails_closed_before_service_execution(): void
    {
        $this->subtitlesService->shouldNotReceive('searchByQuery');

        $this->post(route('subtitles.search-query'), [
            'query' => 'The Show',
        ])->assertStatus(410);
    }

    public function test_search_by_query_fails_closed_before_request_validation(): void
    {
        $this->post(route('subtitles.search-query'), [
            'query' => 'ab',
        ])->assertStatus(410);
    }
}
