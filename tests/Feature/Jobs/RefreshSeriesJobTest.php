<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RefreshSeriesJob;
use App\Models\Serie;
use App\Services\DatabaseRefreshProgressService;
use App\Services\SeriesRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RefreshSeriesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_refreshes_series_through_shared_service_and_records_success(): void
    {
        $serie = Serie::create([
            'name' => 'Refresh Me',
            'trakt_id' => 101,
        ]);

        $refresh = Mockery::mock(SeriesRefreshService::class);
        $refresh->shouldReceive('refresh')
            ->once()
            ->with(Mockery::on(fn (Serie $value): bool => $value->is($serie)), true)
            ->andReturn($serie);

        $progress = Mockery::mock(DatabaseRefreshProgressService::class);
        $progress->shouldReceive('recordSuccess')->once()->with('Refresh Me');

        $job = new RefreshSeriesJob($serie->id);
        $job->handle($refresh, $progress);
    }

    public function test_job_records_missing_trakt_id_as_failure_without_calling_remote_refresh(): void
    {
        $serie = Serie::create([
            'name' => 'Legacy Local',
            'tvdb_id' => 1234,
        ]);

        $refresh = Mockery::mock(SeriesRefreshService::class);
        $refresh->shouldNotReceive('refresh');

        $progress = Mockery::mock(DatabaseRefreshProgressService::class);
        $progress->shouldReceive('recordFailure')
            ->once()
            ->with($serie->id, 'Legacy Local');

        $job = new RefreshSeriesJob($serie->id);
        $job->handle($refresh, $progress);
    }

    public function test_job_continues_batch_semantics_after_non_rate_limit_failure(): void
    {
        $serie = Serie::create([
            'name' => 'Broken Remote',
            'trakt_id' => 303,
        ]);

        $refresh = Mockery::mock(SeriesRefreshService::class);
        $refresh->shouldReceive('refresh')
            ->once()
            ->andThrow(new \RuntimeException('upstream internal detail'));

        $progress = Mockery::mock(DatabaseRefreshProgressService::class);
        $progress->shouldReceive('recordFailure')
            ->once()
            ->with($serie->id, 'Broken Remote');

        $job = new RefreshSeriesJob($serie->id);
        $job->handle($refresh, $progress);
    }
}
