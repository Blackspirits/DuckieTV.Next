<?php

namespace Tests\Feature\Services;

use App\Models\Serie;
use App\Services\FavoritesService;
use App\Services\SeriesRefreshService;
use App\Services\TraktService;
use Mockery;
use Tests\TestCase;

class SeriesRefreshServiceTest extends TestCase
{
    public function test_bulk_refresh_path_uses_shared_trakt_throttle_and_persists_through_favorites_service(): void
    {
        $serie = new Serie([
            'name' => 'Existing',
            'trakt_id' => 101,
        ]);

        $freshData = [
            'title' => 'Existing',
            'trakt_id' => 101,
            'seasons' => [],
        ];

        $updated = new Serie([
            'name' => 'Existing',
            'trakt_id' => 101,
        ]);

        $trakt = Mockery::mock(TraktService::class);
        $trakt->shouldReceive('withThrottling')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());
        $trakt->shouldReceive('serie')
            ->once()
            ->with('101')
            ->andReturn($freshData);

        $favorites = Mockery::mock(FavoritesService::class);
        $favorites->shouldReceive('addFavorite')
            ->once()
            ->with($freshData, [], true)
            ->andReturn($updated);

        $service = new SeriesRefreshService($favorites, $trakt);

        $this->assertSame($updated, $service->refresh($serie, true));
    }

    public function test_shared_refresh_rejects_series_without_trakt_identity(): void
    {
        $trakt = Mockery::mock(TraktService::class);
        $trakt->shouldNotReceive('serie');
        $trakt->shouldNotReceive('withThrottling');

        $favorites = Mockery::mock(FavoritesService::class);
        $favorites->shouldNotReceive('addFavorite');

        $service = new SeriesRefreshService($favorites, $trakt);

        $this->expectException(\InvalidArgumentException::class);
        $service->refresh(new Serie(['name' => 'Legacy']));
    }
}
