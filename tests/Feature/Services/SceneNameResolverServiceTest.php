<?php

namespace Tests\Feature\Services;

use App\Models\Episode;
use App\Models\Serie;
use App\Services\SceneNameResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SceneNameResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_persisted_custom_search_string_is_appended_to_episode_search(): void
    {
        $serie = Serie::create([
            'name' => 'The Example Show',
            'trakt_id' => 100,
            'customSearchString' => 'PROPER 1080p',
        ])->fresh();

        $episode = Episode::create([
            'serie_id' => $serie->id,
            'seasonnumber' => 2,
            'episodenumber' => 3,
            'trakt_id' => 1001,
        ]);

        $search = app(SceneNameResolverService::class)
            ->getSearchStringForEpisode($serie, $episode);

        $this->assertSame('The Example Show s02e03 PROPER 1080p', $search);
    }

    public function test_filter_name_always_returns_a_string(): void
    {
        $service = app(SceneNameResolverService::class);
        $filtered = $service->filterName('Pokémon! (2024)');

        $this->assertIsString($filtered);
        $this->assertSame('Pokemon ', $filtered);
    }
}
