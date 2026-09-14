<?php

namespace Tests\Feature\Services;

use App\Services\DatabaseMaintenanceLock;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DatabaseMaintenanceLockTest extends TestCase
{
    public function test_lock_blocks_overlapping_database_maintenance_until_owner_releases_it(): void
    {
        Cache::flush();

        $lock = app(DatabaseMaintenanceLock::class);
        $owner = $lock->acquire();

        $this->assertNotNull($owner);
        $this->assertNull($lock->acquire());

        $lock->release($owner);

        $nextOwner = $lock->acquire();
        $this->assertNotNull($nextOwner);

        $lock->release($nextOwner);
    }
}
