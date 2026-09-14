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
     * Remove DuckieTV user state while preserving the historical settings
     * that survive both standalone wipes and wipe-before-restore.
     */
    public function wipeUserDatabase(): void
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

        // SettingsService and FavoritesService are singletons. Reload settings
        // and invalidate settings-derived favorite state after the transaction
        // so later restore work cannot observe pre-wipe preferences.
        app(SettingsService::class)->restore();
        app(FavoritesService::class)->resetCachedSettings();
    }
}
