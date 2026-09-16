<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\ShowSettingsRequest;
use App\Jobs\RefreshDatabaseJob;
use App\Models\Serie;
use App\Services\AutoBackupLifecycleService;
use App\Services\AutoDownloadLifecycleService;
use App\Services\DatabaseMaintenanceLock;
use App\Services\DatabaseMaintenanceService;
use App\Services\DatabaseRefreshProgressService;
use App\Services\SubtitlesService;
use App\Services\TorrentClientService;
use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    protected $translationService;

    protected $torrentClientService;

    protected SubtitlesService $subtitlesService;

    protected $backupService;

    protected AutoDownloadLifecycleService $autoDownloadLifecycle;

    public function __construct(
        TranslationService $translationService,
        TorrentClientService $torrentClientService,
        SubtitlesService $subtitlesService,
        \App\Services\BackupService $backupService,
        AutoDownloadLifecycleService $autoDownloadLifecycle
    ) {
        $this->translationService = $translationService;
        $this->torrentClientService = $torrentClientService;
        $this->subtitlesService = $subtitlesService;
        $this->backupService = $backupService;
        $this->autoDownloadLifecycle = $autoDownloadLifecycle;
    }

    /**
     * Display the settings menu (Left Panel).
     */
    public function index(): View
    {
        return view('settings.index', [
            'locales' => $this->translationService->getAvailableLocales(),
            'supportedClients' => $this->getSupportedClients(),
        ]);
    }

    private function getSupportedClients(): array
    {
        return collect($this->torrentClientService->getAvailableClients())->mapWithKeys(function ($clientName) {
            $client = $this->torrentClientService->getClient($clientName);
            $presenter = new \App\Presenters\TorrentClientPresenter($client);

            return [
                $clientName => [
                    'id' => $presenter->getId(),
                    'name' => $presenter->getName(),
                    'icon' => $presenter->getIcon(),
                    'css_class' => $presenter->getCssClass(),
                ],
            ];
        })->toArray();
    }

    /**
     * Display a specific settings section (Right Panel).
     */
    public function show(ShowSettingsRequest $request, string $section): View
    {
        // Validation is handled by ShowSettingsRequest

        $data = [];
        if ($section === 'language') {
            $data['locales'] = $this->translationService->getAvailableLocales();
        }

        if ($section === 'subtitles') {
            $data['subtitleLanguages'] = $this->subtitlesService->getLanguages();
            $data['subtitleShortCodes'] = $this->subtitlesService->getShortCodes();
        }

        if ($section === 'torrent') {
            $data['supportedClients'] = $this->getSupportedClients();
        }

        $data['section'] = $section;

        return view("settings.$section", $data);
    }

    /**
     * Update settings for a specific section.
     */
    public function update(Request $request, string $section)
    {
        $allowed = [
            'display' => \App\Http\Requests\Settings\UpdateDisplaySettingsRequest::class,
            'language' => \App\Http\Requests\Settings\UpdateLanguageSettingsRequest::class,
            'miscellaneous' => \App\Http\Requests\Settings\UpdateMiscellaneousSettingsRequest::class,
            'backup' => \App\Http\Requests\Settings\UpdateBackupSettingsRequest::class,
            'calendar' => \App\Http\Requests\Settings\UpdateCalendarSettingsRequest::class,
            'torrent-search' => \App\Http\Requests\Settings\UpdateTorrentSearchSettingsRequest::class,
            'subtitles' => \App\Http\Requests\Settings\UpdateSubtitlesSettingsRequest::class,
            'torrent' => \App\Http\Requests\Settings\UpdateTorrentSettingsRequest::class,
            'auto-download' => \App\Http\Requests\Settings\UpdateAutoDownloadSettingsRequest::class,
            'trakttv' => \App\Http\Requests\Settings\UpdateTraktSettingsRequest::class,
        ];

        if (! array_key_exists($section, $allowed)) {
            abort(404);
        }

        $wasAutoDownloadEligible = (bool) settings()->get('torrenting.enabled', true)
            && (bool) settings()->get('torrenting.autodownload', false);

        // Expand dot-notated keys (e.g. "torrenting.client") into nested arrays
        // because Laravel validation expects nesting for dot-notation rules.
        $data = $request->all(); // Works for JSON and form data
        $expanded = [];
        foreach ($data as $key => $value) {
            if (str_contains($key, '.')) {
                data_set($expanded, $key, $value);
            } else {
                $expanded[$key] = $value;
            }
        }
        $request->merge($expanded);

        // Validate using the specific FormRequest rules but manually
        $formRequest = app($allowed[$section]);
        $rules = $formRequest->rules();

        // Once a field is explicitly present, "sometimes" is redundant and can
        // prevent its real rules from running. Laravel 12's nested presence
        // check uses the literal "__missing__" as an internal sentinel, so a
        // submitted value with that exact string can otherwise be mistaken for
        // an absent dotted field. Strip only the presence modifier from fields
        // that are actually present; absent fields keep partial-update semantics.
        foreach ($rules as $key => $rule) {
            if (! \Illuminate\Support\Arr::has($expanded, $key)) {
                continue;
            }

            if (is_string($rule)) {
                $rule = preg_replace('/(^|\\|)sometimes(\\||$)/', '$1', $rule);
                $rules[$key] = trim((string) $rule, '|');
            } elseif (is_array($rule)) {
                $rules[$key] = array_values(array_filter(
                    $rule,
                    static fn ($rulePart): bool => $rulePart !== 'sometimes'
                ));
            }
        }

        $validator = \Illuminate\Support\Facades\Validator::make($expanded, $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // Laravel normalizes an empty string to null before validation. The
        // historical Torrent Search contract stores "" to mean all qualities.
        if (
            $section === 'torrent-search'
            && \Illuminate\Support\Arr::has($validated, 'torrenting.searchquality')
            && \Illuminate\Support\Arr::get($validated, 'torrenting.searchquality') === null
        ) {
            \Illuminate\Support\Arr::set($validated, 'torrenting.searchquality', '');
        }

        // Ensure booleans are included even if missing from request (unchecked checkboxes).
        // A `sometimes` rule explicitly means an absent field is a partial update and
        // must not overwrite a sibling boolean merely because it shares a prefix.
        $rawData = $request->all();
        foreach ($rules as $key => $rule) {
            $ruleList = is_array($rule) ? $rule : explode('|', $rule);
            if (in_array('sometimes', $ruleList, true) || ! in_array('boolean', $ruleList, true)) {
                continue;
            }

            if (! \Illuminate\Support\Arr::has($validated, $key)) {
                $prefix = str_contains($key, '.') ? explode('.', $key)[0] : $key;
                // Check if there are other fields in the same configuration group present
                $otherFieldsInGroup = collect($rawData)->keys()
                    ->filter(fn ($k) => str_starts_with($k, $prefix.'.'))
                    ->count();

                if ($otherFieldsInGroup > 0) {
                    \Illuminate\Support\Arr::set($validated, $key, false);
                }
            }
        }

        // Historical changeLanguage() persisted both keys together. Keep the
        // generic settings update path unchanged for other sections, but mirror
        // the selected locale into application.language for backup compatibility.
        if ($section === 'language') {
            $locale = \Illuminate\Support\Arr::get($validated, 'application.locale');
            if (is_string($locale)) {
                \Illuminate\Support\Arr::set($validated, 'application.language', $locale);
            }
        }

        // Preserve validated array-valued settings as whole leaves. Arr::dot()
        // would otherwise expand them into indexed keys such as
        // subtitles.languages.0 and leave the canonical array setting unchanged.
        $arraySettings = [];
        foreach ($rules as $key => $rule) {
            $isArrayRule = $rule === 'array'
                || (is_array($rule) && in_array('array', $rule, true));

            if (! $isArrayRule || ! \Illuminate\Support\Arr::has($validated, $key)) {
                continue;
            }

            $arraySettings[$key] = \Illuminate\Support\Arr::get($validated, $key);
            \Illuminate\Support\Arr::forget($validated, $key);
        }

        // validated() returns nested arrays corresponding to dot rules.
        // Flatten scalar leaves back to dot notation, then restore whole arrays.
        $flattened = array_merge(\Illuminate\Support\Arr::dot($validated), $arraySettings);

        foreach ($flattened as $key => $value) {
            settings($key, $value);
        }

        $isAutoDownloadEligible = (bool) settings()->get('torrenting.enabled', true)
            && (bool) settings()->get('torrenting.autodownload', false);

        if (! $wasAutoDownloadEligible && $isAutoDownloadEligible) {
            $this->autoDownloadLifecycle->dispatchIfEligible();
        }

        $res = ['success' => true, 'message' => 'Settings saved successfully.'];

        if ($request->has('test') && $section === 'torrent') {
            $client = $this->torrentClientService->getActiveClient();
            if ($client) {
                // Refresh config from settings store before testing
                $client->readConfig();
                try {
                    $connected = $client->connect();
                    $res['connection_success'] = $connected;
                    if ($connected) {
                        $res['message'] = "Connected to {$client->getName()} successfully!";
                        $triggered = $this->autoDownloadLifecycle->recordClientConnectivity($client->getId(), true);
                        if (! $triggered) {
                            $this->autoDownloadLifecycle->dispatchIfEligible();
                        }
                    } else {
                        $this->autoDownloadLifecycle->recordClientConnectivity($client->getId(), false);
                        $res['connection_error'] = "Failed to connect to {$client->getName()} for unknown reasons. Check your settings and server status.";
                    }
                } catch (\Exception $e) {
                    $this->autoDownloadLifecycle->recordClientConnectivity($client->getId(), false);
                    $res['connection_success'] = false;
                    $res['connection_error'] = "Connection to {$client->getName()} failed: ".$e->getMessage();
                }
            }
        }

        return response()->json($res);
    }

    /**
     * Download a manual backup in the historical DuckieTV JSON format.
     */
    public function downloadBackup(DatabaseMaintenanceLock $maintenanceLock)
    {
        $lockOwner = $maintenanceLock->acquire();

        if ($lockOwner === null) {
            return response()->json([
                'success' => false,
                'message' => 'Another database maintenance operation is already running.',
            ], 409);
        }

        try {
            return $this->createBackupDownloadResponse();
        } finally {
            $maintenanceLock->release($lockOwner);
        }
    }

    public function autoBackupStatus(
        Request $request,
        AutoBackupLifecycleService $autoBackup,
        DatabaseMaintenanceLock $maintenanceLock
    ) {
        $lockOwner = $maintenanceLock->acquire();

        if ($lockOwner === null) {
            return response()->json([
                'success' => false,
                'message' => 'Another database maintenance operation is already running.',
            ], 409);
        }

        try {
            $timezone = $request->query('timezone');
            $timezone = is_string($timezone) ? $timezone : null;

            return response()->json($autoBackup->status(null, $timezone));
        } finally {
            $maintenanceLock->release($lockOwner);
        }
    }

    public function downloadAutoBackup(
        DatabaseMaintenanceLock $maintenanceLock,
        AutoBackupLifecycleService $autoBackup
    ) {
        $lockOwner = $maintenanceLock->acquire();

        if ($lockOwner === null) {
            return response()->json([
                'success' => false,
                'message' => 'Another database maintenance operation is already running.',
            ], 409);
        }

        try {
            // Historical BackupDialogCtrl updates autobackup.lastrun immediately
            // when Create Backup is chosen, before the asynchronous backup
            // generation completes. Persist it first so the backup contains the
            // new schedule anchor as the Angular version did.
            $autoBackup->recordRun();

            return $this->createBackupDownloadResponse();
        } finally {
            $maintenanceLock->release($lockOwner);
        }
    }

    private function createBackupDownloadResponse(): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        try {
            $json = json_encode(
                $this->backupService->createBackup(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

            $filename = 'DuckieTV '.now()->format('Y-m-d').'.backup';

            return response()->make($json, 200, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, max-age=0',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Backup export failed.', [
                'exception' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Backup export failed.',
            ], 500);
        }
    }

    /**
     * Wipe DuckieTV user data using the same historical contract as
     * wipe-before-restore.
     */
    public function wipe(
        DatabaseMaintenanceService $databaseMaintenance,
        DatabaseMaintenanceLock $maintenanceLock
    ) {
        $lockOwner = $maintenanceLock->acquire();

        if ($lockOwner === null) {
            return response()->json([
                'success' => false,
                'message' => 'Another database maintenance operation is already running.',
            ], 409);
        }

        try {
            $databaseMaintenance->wipeUserDatabase();

            return response()->json([
                'success' => true,
                'message' => 'Database wiped successfully.',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Database wipe failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Database wipe failed.',
            ], 500);
        } finally {
            $maintenanceLock->release($lockOwner);
        }
    }

    /**
     * Re-fetch all current favorites from Trakt using the same refresh path
     * as the per-series action.
     */
    public function refreshDatabase(
        DatabaseMaintenanceLock $maintenanceLock,
        DatabaseRefreshProgressService $progress
    ) {
        $lockOwner = $maintenanceLock->acquire();

        if ($lockOwner === null) {
            return response()->json([
                'success' => false,
                'message' => 'Another database maintenance operation is already running.',
            ], 409);
        }

        try {
            $seriesIds = Serie::query()
                ->whereNotNull('name')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $progress->queued(count($seriesIds));
            RefreshDatabaseJob::dispatch($seriesIds, $lockOwner);

            return response()->json([
                'success' => true,
                'message' => 'Database refresh started in background.',
                'status' => 'started',
                'total' => count($seriesIds),
            ]);
        } catch (\Throwable $e) {
            $maintenanceLock->release($lockOwner);
            $progress->fail('Database refresh failed to start.');

            \Illuminate\Support\Facades\Log::error('Database refresh dispatch failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Database refresh failed to start.',
            ], 500);
        }
    }

    public function refreshDatabaseProgress(DatabaseRefreshProgressService $progress)
    {
        return response()->json($progress->get());
    }

    /**
     * Restore backup from file.
     */
    public function restore(
        \Illuminate\Http\Request $request,
        DatabaseMaintenanceLock $maintenanceLock
    ) {
        $request->validate([
            'backup_file' => 'required|file|mimetypes:application/json,text/plain|max:10240', // 10MB max
            'wipe' => 'sometimes|boolean',
        ]);

        $lockOwner = null;

        try {
            $file = $request->file('backup_file');
            $json = file_get_contents($file->getRealPath());
            $data = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid JSON file: '.json_last_error_msg(),
                ], 422);
            }

            $lockOwner = $maintenanceLock->acquire();

            if ($lockOwner === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Another database maintenance operation is already running.',
                ], 409);
            }

            \Illuminate\Support\Facades\Cache::put('backup_progress', [
                'percent' => 0,
                'status' => 'queued',
                'message' => 'Restore queued...',
                'logs' => [],
                'show_progress' => null,
                'batch_id' => null,
            ]);

            // Delegate to BackupService via Job for async processing.
            // The maintenance lock remains owned until the restore batch finishes.
            \App\Jobs\RestoreBackupJob::dispatch(
                $data,
                $request->boolean('wipe'),
                $lockOwner
            );

            return response()->json([
                'success' => true,
                'message' => 'Restore started in background. Please wait...',
                'status' => 'started',
            ]);

        } catch (\Throwable $e) {
            if ($lockOwner !== null) {
                $maintenanceLock->release($lockOwner);

                \Illuminate\Support\Facades\Cache::put('backup_progress', [
                    'percent' => 0,
                    'status' => 'failed',
                    'message' => 'Restore failed to start.',
                    'logs' => [],
                    'show_progress' => null,
                    'batch_id' => null,
                ]);
            }

            \Illuminate\Support\Facades\Log::error('Restore dispatch failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Restore failed to start.',
            ], 500);
        }
    }

    /**
     * Get the current progress of the restore job.
     */
    public function restoreProgress()
    {
        // Default to idle if no progress is found
        $progress = \Illuminate\Support\Facades\Cache::get('backup_progress', [
            'percent' => 0,
            'status' => 'idle',
            'message' => 'Waiting for start...',
            'logs' => [],
        ]);

        return response()->json($progress);
    }

    /**
     * Cancel the current running restore batch.
     */
    public function cancelRestore()
    {
        $progress = \Illuminate\Support\Facades\Cache::get('backup_progress');

        if (isset($progress['batch_id'])) {
            $batchId = $progress['batch_id'];
            $batch = \Illuminate\Support\Facades\Bus::findBatch($batchId);

            if ($batch) {
                $batch->cancel();

                $progress['status'] = 'cancelling';
                $progress['message'] = 'Cancellation requested...';
                $progress['logs'][] = date('H:i:s').' - User requested cancellation.';
                \Illuminate\Support\Facades\Cache::put('backup_progress', $progress);

                return response()->json(['success' => true, 'message' => 'Cancellation requested.']);
            }
        }

        return response()->json(['success' => false, 'message' => 'No active batch found to cancel.'], 404);
    }
}
