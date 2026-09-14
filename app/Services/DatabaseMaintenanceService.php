<?php

namespace App\Services;

use App\Models\AutoDownloadActivity;
use App\Models\Episode;
use App\Models\Jackett;
use App\Models\Season;
use App\Models\Serie;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class DatabaseMaintenanceService
{
    /**
     * Remove user library state before a backup restore while preserving
     * the historical DuckieTV settings that must survive a wipe.
     */
    public function wipeForRestore(): void
    {
        DB::transaction(function (): void {
            AutoDownloadActivity::query()->delete();
            Episode::query()->delete();
            Season::query()->delete();
            Serie::query()->delete();
            Jackett::query()->delete();

            Setting::query()
                ->where('settings.key', 'not like', 'database.version%')
                ->where('settings.key', 'not like', 'utorrent.token%')
                ->delete();
        });

        // SettingsService is a singleton. Reload it after the transaction so
        // callers cannot observe settings that were removed by the wipe.
        app(SettingsService::class)->restore();
    }
}
