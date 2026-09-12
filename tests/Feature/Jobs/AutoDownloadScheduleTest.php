<?php

namespace Tests\Feature\Jobs;

use App\Jobs\PruneAutoDLActivitiesJob;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class AutoDownloadScheduleTest extends TestCase
{
    public function test_periodic_lifecycle_is_registered_every_fifteen_minutes_and_pruning_daily(): void
    {
        $events = app(Schedule::class)->events();

        $autoDownload = collect($events)->first(
            fn ($event): bool => ($event->description ?? null) === 'auto-download:lifecycle'
        );

        $prune = collect($events)->first(function ($event): bool {
            return str_contains((string) ($event->description ?? ''), PruneAutoDLActivitiesJob::class)
                || str_contains((string) ($event->command ?? ''), PruneAutoDLActivitiesJob::class);
        });

        $this->assertNotNull($autoDownload, 'Auto-download lifecycle is not registered with the scheduler.');
        $this->assertSame('*/15 * * * *', $autoDownload->expression);

        $this->assertNotNull($prune, 'PruneAutoDLActivitiesJob is not registered with the scheduler.');
        $this->assertSame('0 0 * * *', $prune->expression);
    }
}
