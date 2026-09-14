<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class DatabaseMaintenanceLock
{
    private const LOCK_KEY = 'database-maintenance:exclusive';

    private const TTL_SECONDS = 21600;

    public function acquire(): ?string
    {
        $lock = Cache::lock(self::LOCK_KEY, self::TTL_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        return $lock->owner();
    }

    public function release(?string $owner): void
    {
        if ($owner === null || $owner === '') {
            return;
        }

        Cache::restoreLock(self::LOCK_KEY, $owner)->release();
    }
}
