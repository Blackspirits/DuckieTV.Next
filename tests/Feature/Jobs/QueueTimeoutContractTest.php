<?php

namespace Tests\Feature\Jobs;

use App\Jobs\AutoDownloadJob;
use App\Jobs\RestoreBackupJob;
use App\Jobs\RestoreShowJob;
use App\Jobs\TraktUpdateJob;
use App\Services\AutoDownloadLifecycleService;
use Tests\TestCase;

class QueueTimeoutContractTest extends TestCase
{
    public function test_database_retry_after_exceeds_every_managed_worker_or_job_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $workerTimeout = (int) config('nativephp.queue_workers.default.timeout');

        $restoreBackup = new RestoreBackupJob([]);
        $restoreShow = new RestoreShowJob('1', []);
        $traktUpdate = new TraktUpdateJob;

        $this->assertGreaterThan($workerTimeout, $retryAfter);
        $this->assertGreaterThan((int) $restoreBackup->timeout, $retryAfter);
        $this->assertGreaterThan((int) $restoreShow->timeout, $retryAfter);
        $this->assertGreaterThan((int) $traktUpdate->timeout, $retryAfter);
    }

    public function test_auto_download_keeps_a_shorter_explicit_recovery_contract(): void
    {
        $job = new AutoDownloadJob;

        $this->assertSame(90, AutoDownloadLifecycleService::RECOVERY_AFTER_SECONDS);
        $this->assertGreaterThan($job->timeout, AutoDownloadLifecycleService::RECOVERY_AFTER_SECONDS);
        $this->assertGreaterThan(
            AutoDownloadJob::OVERLAP_EXPIRY_SECONDS,
            AutoDownloadLifecycleService::RECOVERY_AFTER_SECONDS
        );
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after'),
            AutoDownloadLifecycleService::RECOVERY_AFTER_SECONDS
        );
    }
}
