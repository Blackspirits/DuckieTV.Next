<?php

namespace Tests\Feature\Jobs;

use App\Jobs\PruneAutoDLActivitiesJob;
use App\Models\AutoDownloadActivity;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneAutoDLActivitiesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pruning_is_idempotent_and_preserves_the_30_day_boundary(): void
    {
        Carbon::setTestNow('2026-09-12 12:00:00');

        $expired = $this->activity(now()->subDays(31)->timestamp, 'expired');
        $boundary = $this->activity(now()->subDays(30)->timestamp, 'boundary');
        $recent = $this->activity(now()->subDay()->timestamp, 'recent');

        $job = new PruneAutoDLActivitiesJob;
        $job->handle();
        $job->handle();

        $this->assertDatabaseMissing('autodl_activities', ['id' => $expired->id]);
        $this->assertDatabaseHas('autodl_activities', ['id' => $boundary->id]);
        $this->assertDatabaseHas('autodl_activities', ['id' => $recent->id]);
    }

    protected function activity(int $timestamp, string $name): AutoDownloadActivity
    {
        return AutoDownloadActivity::create([
            'search' => $name,
            'search_provider' => '',
            'search_extra' => '',
            'status' => 4,
            'extra' => '',
            'serie_name' => 'Lifecycle Show',
            'episode_formatted' => 's01e01',
            'timestamp' => $timestamp,
        ]);
    }
}
