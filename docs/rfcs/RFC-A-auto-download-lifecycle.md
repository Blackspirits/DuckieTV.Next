# RFC A — Canonical auto-download implementation and desktop lifecycle

Status: **Proposed — revised after independent review**  
Audit findings: **DTV-003, DTV-005, DTV-018**  
Base: PR08 `681a5fafab165f5f11a1b87d52e551d65dc28420`  
Upstream classification: **RFC_REQUIRED before code**

## Purpose

DuckieTV.Next currently has two materially different auto-download business implementations (`AutoDownloadJob` and `AutoDownloadService`) and no established production periodic wiring for either one. The implementation that is already reachable from the UI (`AutoDownloadService`) also contains live schema/type, result-size, torrent-identity, eligibility, and checkpoint defects.

This RFC proposes one canonical implementation and the lifecycle contract that later remediation PRs must preserve. It is an audit proposal, not an upstream maintainer decision and not authorization to merge, release, or publish code.

## Decision classification

Every behavioral decision in this RFC is classified as one of:

- **HISTORICAL COMPATIBILITY** — directly supported by DuckieTV Angular behavior;
- **BUG FIX** — intentionally corrects a defect in DuckieTV Angular or DuckieTV.Next while preserving user-facing setting meaning where possible;
- **ROBUSTNESS** — strengthens lifecycle/error behavior without changing intended product semantics;
- **PRODUCT DECISION** — behavior that cannot be derived unambiguously from the historical implementation and therefore requires owner acceptance.

## Evidence boundary

The proposal is based on:

- static inspection of DuckieTV.Next at exact base `681a5fafab165f5f11a1b87d52e551d65dc28420`;
- comparison with DuckieTV Angular at `597eae17538b6b870ad790ee7ee9ac59b1c5363d`;
- deterministic PHP/framework semantics where explicitly stated;
- current Laravel 12, PHP PCNTL, and NativePHP Desktop v2 documentation/source.

No real periodic auto-download run, suspend/resume experiment, long-running queue benchmark, hostile-network run, real torrent download, or model/evidence run is claimed.

### Reproducible source anchors

DuckieTV.Next base:

- `app/Jobs/AutoDownloadJob.php`
- `app/Services/AutoDownloadService.php`
- `app/Services/TorrentSearchService.php`
- `app/Services/TorrentSearchEngines/GenericSearchEngine.php`
- `app/Services/TorrentSearchEngines/ThePirateBayEngine.php`
- `app/Services/TorrentSearchEngines/OneThreeThreeSevenXEngine.php`
- `app/Services/TorrentSearchEngines/ShowRSSEngine.php`
- `app/Support/MagnetUri.php`
- `app/Services/SettingsService.php`
- `app/Http/Controllers/EpisodeController.php`
- `app/Http/Controllers/AutoDLStatusController.php`
- `app/Http/Requests/Settings/UpdateAutoDownloadSettingsRequest.php`
- `resources/views/settings/auto-download.blade.php`
- `routes/console.php`
- `config/nativephp.php`
- `config/queue.php`
- `config/cache.php`
- `app/Models/Serie.php`
- `app/Models/Episode.php`
- `app/Models/AutoDownloadActivity.php`
- `database/migrations/2026_02_14_000001_create_series_table.php`
- `tests/Feature/Jobs/AutoDownloadJobTest.php`
- `tests/Unit/Services/AutoDownloadServiceTest.php`

DuckieTV Angular reference:

- `js/services/AutoDownloadService.js`
- `js/services/SettingsService.js`
- `js/controllers/settings/SettingsTorrentCtrl.js`
- `js/controllers/settings/SerieSettingsCtrl.js`
- `js/controllers/sidepanel/SidepanelEpisodeCtrl.js`
- `js/controllers/sidepanel/SidepanelSeasonCtrl.js`
- `templates/sidepanel/episode-details.html`
- `templates/settings/auto-download.html`
- `js/utility.js`
- `_locales/en_us.json`

Framework references:

- Laravel 12 queues: https://laravel.com/docs/12.x/queues
- Laravel 12 scheduler: https://laravel.com/docs/12.x/scheduling
- PHP PCNTL: https://www.php.net/pcntl
- NativePHP Desktop v2 queues: https://nativephp.com/docs/desktop/2/digging-deeper/queues

## Current-state observations

### Two business engines

`AutoDownloadJob` contains candidate selection, eligibility checks, search/filter logic, torrent launch logic, info-hash extraction, delay clamping, and last-run updates. `AutoDownloadService` independently implements much of the same domain behavior and is already used by the episode manual-download and Auto-Download Status surfaces.

Keeping both authoritative would make parity and future maintenance non-deterministic.

### The canonical-Service candidate is currently functionally broken

`Serie` casts `displaycalendar`, `autoDownload`, `ignoreHideSpecials`, `ignoreGlobalQuality`, `ignoreGlobalIncludes`, and `ignoreGlobalExcludes` to booleans.

`AutoDownloadService` mixes snake_case reads with strict integer comparisons. In particular, `displaycalendar !== 1` is true when Eloquent returns boolean `true`, so a persisted visible series can be rejected as hidden. Renaming `auto_download` to `autoDownload` without changing comparison semantics would likewise leave `false === 0` assumptions incorrect.

This is a live contract defect, not merely naming style.

### Search-result size is heterogeneous and unsafe

The current search layer does not expose one typed size contract:

- `GenericSearchEngine::sizeToMB()` emits human-formatted strings such as `"1,500.00 MB"` using `number_format()`;
- `AutoDownloadService::filterBySize()` casts the first whitespace-delimited token to `float`, so formatted thousands separators corrupt numeric interpretation (`(float) "1,500.00"` becomes `1.0` in PHP);
- `ShowRSSEngine` emits `"n/a"`;
- engine-specific parsers such as The Pirate Bay and 1337x transform the source size before the generic conversion and therefore need explicit regression coverage.

The existing unit test that injects raw `"1.5 GB"` directly into `filterBySize()` does not represent the generic production path and must not be treated as parity evidence.

### Torrent identity persistence is incomplete

`AutoDownloadService::download()` persists `Episode::magnetHash` only when the search result already contains `infoHash`, but the generic search-result shape does not create that field. Its magnet path can therefore launch a torrent without persisting the identity used for duplicate/progress tracking. The torrent-URL path also reads `$item['infoHash']` without establishing that the key exists.

The Service has a private `extractHash()` helper that is not used. `App\Support\MagnetUri::extractInfoHash()` exists but currently supports only a 40-character hexadecimal hash, while DuckieTV Angular also converted 32-character base32 BTIH values to canonical hexadecimal form.

### Periodic connected-client semantics have drifted

DuckieTV Angular performed the candidate scan only while the active torrent client was connected. If it was not connected, no candidate scan occurred and `autodownload.lastrun` did not advance. It also retriggered the check when the `torrentclient:connected` event fired.

The current Service may continue scanning with no connected client and then advance `lastrun`, which can consume recovery coverage without a usable download path.

### Existing-torrent semantics have drifted

DuckieTV Angular skipped an episode only when both conditions held:

```text
episode.magnetHash is present
AND that hash is currently present in the remote torrent client
```

The current Service skips whenever `magnetHash` is non-empty, even when the torrent has been removed from the client. The Service already builds a `remoteTorrents` map but does not use it for this decision.

### No production periodic wiring

`routes/console.php` schedules only `PruneAutoDLActivitiesJob`. No production schedule/dispatch of `AutoDownloadJob` is present and no production lifecycle caller of `AutoDownloadService::check()` is established.

### Migrated settings UI conflicts with the original contract

The current Blade auto-download page describes `autodownload.period` as a frequency in hours, defaults it to 6, and reads `autodownload.enabled`. That conflicts with `SettingsService` and DuckieTV Angular.

The canonical persisted settings contract is:

- `torrenting.enabled`: torrenting master switch;
- `torrenting.autodownload`: periodic auto-download switch;
- `autodownload.period`: integer lookback/overlap window in **days**, default **1**, accepted UI/server range **1–21**;
- `autodownload.delay`: integer delay in **minutes**, default **15**;
- per-series `customDelay`: integer delay in **minutes** or `null`;
- recurring auto-download cadence: **15 minutes**, independent of `autodownload.period`.

The current FormRequest validates `period`/`delay` only as numeric and does not enforce the full canonical range/unit contract.

### Queue timing is not portable as currently described

The database queue has `retry_after = 90` seconds. NativePHP config declares a 300-second worker timeout, while `AutoDownloadJob` declares `$timeout = 1800` seconds. Laravel allows a job-level timeout to take precedence over the worker timeout.

However Laravel queue timeouts require PCNTL, and PHP does not provide PCNTL on Windows. Therefore `$timeout` / worker timeout cannot be the only enforcement mechanism for a cross-platform NativePHP desktop application. `retry_after` can make a still-running database job reservable again, so execution overlap must be prevented independently.

## Decision

### 1. `AutoDownloadService` becomes the sole business implementation

**Classification: ROBUSTNESS / maintainability.**

`AutoDownloadService` becomes the canonical application/domain implementation because it is already shared by user-facing manual/status surfaces and already owns persistent `AutoDownloadActivity` recording.

`AutoDownloadJob` becomes a thin queued orchestrator only. Its final responsibility is to invoke the canonical Service plus lifecycle-specific queue contracts.

Before deleting duplicated Job logic, PR09 must explicitly inventory and preserve/migrate behavior that exists only or more correctly there. At minimum:

- persisted camelCase model attributes;
- boolean truth semantics;
- custom-delay clamp against `periodDays × 24 × 60` for periodic checks;
- info-hash derivation/persistence;
- configured per-series seeders/includes/excludes/provider/size/delay overrides;
- torrent-client success ordering.

No business behavior is allowed to disappear merely because the Job is reduced to an orchestrator.

### 2. Periodic and explicit manual download have different eligibility contracts

#### Periodic check

**Classification: HISTORICAL COMPATIBILITY, with server-side revalidation as ROBUSTNESS.**

A periodic full check requires:

- `torrenting.enabled = true`;
- `torrenting.autodownload = true`;
- an active torrent client that is connected/usable;
- series `autoDownload = true`;
- calendar visibility;
- global specials policy with the per-series override;
- episode aired state;
- runtime + effective delay elapsed;
- not already downloaded;
- not already watched;
- TVDB ID available;
- if `magnetHash` exists, skip only when that hash is currently present in the connected remote client.

For periodic delay:

```text
effectiveDelayMinutes = customDelay ?? autodownload.delay
effectiveDelayMinutes = min(effectiveDelayMinutes, periodDays * 24 * 60)
```

The delay clamp is historical compatibility and must not be lost when Job logic is consolidated into the Service.

If no usable connected torrent client exists, the periodic scan does not process candidates and does not advance the checkpoint.

#### Explicit manual episode download

**Historical compatibility:** the original side-panel action called the search/download operation directly rather than the periodic candidate gate. It did not depend on `torrenting.autodownload`, series `autoDownload`, periodic delay, calendar visibility, specials visibility, watched/downloaded state, or existing `magnetHash` state.

The original UI exposed that action only when:

- `torrenting.enabled = true`;
- the episode had aired or was explicitly marked leaked;
- the series had a TVDB ID.

**ROBUSTNESS:** PR09 must enforce those three reachability prerequisites server-side rather than relying on UI visibility alone, and must require a usable torrent client.

**PRODUCT DECISION proposed for owner acceptance:** retain the historical direct-action semantics after those prerequisites. An explicit manual action may intentionally re-download/re-add an episode even if it is marked watched/downloaded or already has a stored magnet hash. It still uses the canonical search/result filters and reports success from the actual torrent-client launch result.

`manualDownload()` must not infer success solely from whether `magnetHash` changed; it returns the actual launch outcome.

### 3. Persisted `Serie` names and value semantics are canonical

**Classification: BUG FIX.**

Use the actual migration/model contract. Relevant fields include:

- `displaycalendar`: boolean cast;
- `autoDownload`: boolean cast;
- `ignoreHideSpecials`: boolean cast;
- `ignoreGlobalQuality`: boolean cast;
- `ignoreGlobalIncludes`: boolean cast;
- `ignoreGlobalExcludes`: boolean cast;
- `customSeeders`: nullable integer;
- `customIncludes`: nullable string;
- `customExcludes`: nullable string;
- `searchProvider`: nullable string;
- `customSearchSizeMin`: nullable integer, user semantics MB;
- `customSearchSizeMax`: nullable integer, user semantics MB;
- `customDelay`: nullable integer, minutes;
- `dlPath`: nullable text;
- `tvdb_id`: nullable integer.

PR09 removes shadow snake_case/legacy reads such as `auto_download`, `custom_seeders`, `custom_includes`, `search_provider`, `TVDB_ID`, etc. rather than introducing aliases that hide schema drift.

Boolean fields are evaluated as booleans, not strict integer sentinels. Examples:

```text
visible series        => (bool) displaycalendar
periodic enabled      => (bool) autoDownload
specials override     => (bool) ignoreHideSpecials
```

Regression fixtures must persist real `Serie` rows so Eloquent casts are exercised. Required regression: a persisted series with `displaycalendar = true` must pass the visibility gate.

### 4. Torrent-result size becomes a typed numeric contract

**Classification: BUG FIX that intentionally diverges from broken historical/current parsing while preserving stored threshold meaning.**

The current human-formatted string contract is replaced at the search-result boundary. A result exposes:

```text
sizeBytes: ?int
```

Machine values must never be produced with `number_format()` or other locale/display formatting.

Canonical unit definitions:

```text
1 KB = 1,000 bytes
1 MB = 1,000,000 bytes
1 GB = 1,000,000,000 bytes
1 TB = 1,000,000,000,000 bytes
1 KiB = 1,024 bytes
1 MiB = 1,048,576 bytes
1 GiB = 1,073,741,824 bytes
1 TiB = 1,099,511,627,776 bytes
```

Existing global and per-series threshold values retain their user-facing/persisted semantics in **decimal MB**. Comparison therefore uses:

```text
thresholdBytes = thresholdMB * 1,000,000
```

Unknown size remains allowed for parity: missing/unknown/`n/a` source size becomes `sizeBytes = null` and does not fail the size filter.

PR09 must normalize at the result-construction/parser boundary and add engine-level regressions for GenericSearchEngine plus each engine-specific size parser that changes the source representation, including The Pirate Bay, 1337x, and ShowRSS. The existing `filter_by_size_no_normalization_parity` test must be replaced because it injects a production-impossible raw-unit shape into the downstream filter.

### 5. Torrent identity is required for successful automatic launch tracking

**Classification: BUG FIX / historical compatibility.**

DuckieTV Angular derived the BTIH from magnet URLs and parsed torrent files/URLs to determine their info hash before persisting `episode.magnetHash`.

Canonical behavior:

1. If a result/details response contains `magnetUrl`, derive a canonical 40-character hexadecimal BTIH before/when launching it.
2. Support both 40-character hexadecimal BTIH and 32-character base32 BTIH inputs; base32 is converted to canonical 40-character hexadecimal form, matching the original utility contract.
3. If only a torrent URL/file is available, obtain the actual torrent info hash before reporting a tracked automatic launch as successful.
4. Persist the canonical hash to `episode.magnetHash` only after the torrent client reports successful addition.
5. A periodic successful launch without a reliable torrent identity is not considered a fully successful tracked auto-download and must not advance that episode into a state that can cause silent repeated launches.
6. The torrent-URL path must never index an undefined `infoHash` result key.

`App\Support\MagnetUri` should be the single helper for magnet identity and must be completed for base32 parity rather than duplicating regex logic in Job and Service.

Required regression: after one successful periodic launch, a second check while that hash remains in the remote client yields `STATUS_HAS_MAGNET` and does not add a second torrent.

### 6. Existing-torrent checks use remote membership, not stored hash alone

**Classification: HISTORICAL COMPATIBILITY.**

For periodic checks:

```text
if episode.magnetHash is present AND remote client contains that hash:
    skip as STATUS_HAS_MAGNET
else:
    continue normal eligibility/search
```

A stored hash whose torrent has been removed from the client does not permanently suppress future periodic recovery attempts.

The remote-torrent map should be populated only after a usable client connection and compared using one canonical case-normalized hash representation.

This periodic guard does not apply to the explicit manual action described in §2.

### 7. `autodownload.period` is lookback/overlap, not cadence

**Historical invariant:**

- integer days;
- default `1`;
- accepted range `1–21`;
- used to overlap the scan before the previous checkpoint so missed episodes can be reconsidered.

The original lower bound is effectively `startOfDay(lastRun - periodDays)` when `lastRun` exists.

**ROBUSTNESS proposal:** PR10 uses a deterministic captured upper bound:

```text
scanTo   = timestamp captured before querying candidates
anchor   = lastRun if present, otherwise scanTo
scanFrom = startOfDay(anchor - periodDays)
```

Advance `autodownload.lastrun` to the captured `scanTo` only after normal scan completion. This is intentionally safer than the original asynchronous completion timestamp and prevents an episode airing during a long scan from falling between the query upper bound and a later checkpoint.

The checkpoint does not advance when:

- periodic auto-download is disabled;
- torrenting is disabled;
- no usable connected torrent client exists;
- the full scan aborts before normal completion.

Per-episode failures may be recorded and processing may continue; the overlap window keeps recent failed candidates eligible for later runs.

### 8. Recurring cadence remains fifteen minutes and reconnection is a trigger

**Classification: HISTORICAL COMPATIBILITY, with scheduler adaptation as ROBUSTNESS.**

The recurring lifecycle cadence is 15 minutes while the desktop application is active. It is independent of `autodownload.period`.

Equivalent lifecycle triggers must cover:

- prompt initial eligibility after startup when both feature switches are enabled;
- prompt eligibility after periodic auto-download is re-enabled;
- prompt eligibility when the torrent client becomes connected/usable, matching the historical `torrentclient:connected` trigger.

The implementation need not reproduce a literal five-second JavaScript timer if NativePHP/Laravel can provide equivalent prompt behavior.

Offline/suspend gaps are recovered through the persisted checkpoint plus overlap; missed wall-clock scheduler ticks are not replayed individually.

### 9. Periodic queue work requires both dispatch uniqueness and execution overlap protection

**Classification: ROBUSTNESS.**

At most one periodic full-check job may be pending/running and at most one full scan may execute at a time.

PR10 must use both layers:

1. **Dispatch uniqueness** — `ShouldBeUnique` (or an equivalent dispatch-level atomic lock) with a bounded `uniqueFor` suppresses repeated scheduler dispatches/backlog.
2. **Execution overlap protection** — `WithoutOverlapping` (or an equivalent execution-level atomic lock) with bounded `expireAfter` prevents concurrent full scans, including a database-queue re-reservation that bypasses the dispatcher uniqueness check.

These mechanisms are complementary, not alternatives.

The execution-overlap path must be compatible with the Job's attempt policy. While `$tries = 1`, failure to acquire the overlap lock must not release the job into an immediate second attempt that then fails by exhausting attempts. `dontRelease()` or an explicitly tested equivalent is required unless the retry policy changes deliberately.

Both lock lifetimes must be finite and derived from the cooperative execution budget in §10 so crash/kill cannot permanently lock auto-download.

The existing database cache store supports Laravel atomic locks; no external lock service is required.

Tests must demonstrate:

- duplicate dispatch suppression;
- no concurrent full scan;
- a direct re-reservation/concurrency scenario is rejected by the execution lock;
- stale/crash lock recovery after bounded expiry;
- overlap rejection does not turn into a spurious failed job.

### 10. Cross-platform execution budget is cooperative; framework timeout is secondary

**Classification: ROBUSTNESS / correctness.**

Laravel's job-level timeout can override worker timeout, but Laravel documents that queue timeouts require PCNTL. PHP does not provide PCNTL on Windows, which is a supported NativePHP target. Therefore PR10 must not rely on `$timeout` / worker timeout as the primary bound.

PR10 establishes a documented cooperative full-scan budget enforced inside the job/service orchestration. The code checks the deadline at safe boundaries, including before starting each candidate and before starting additional expensive network/search work.

Every outbound operation reachable from the periodic scan must also have narrower connection/request budgets so a single blocking operation cannot defeat the cooperative deadline.

The ordering contract becomes:

```text
per-request connect/request budget < cooperative full-scan budget
cooperative full-scan budget + safety margin < database queue retry_after
```

Where PCNTL is available, Laravel job/worker timeout remains a secondary kill safeguard and must be configured consistently with the cooperative budget. Where PCNTL is unavailable, correctness still depends on the cooperative deadline + bounded I/O + execution lock.

The current `1800 / 300 / 90` declarations must not survive as an active contradictory policy.

This RFC intentionally does not choose a numeric scan budget or `retry_after` before bounded search/HTTP timing evidence exists.

### 11. Activity persistence belongs to the canonical Service

**Classification: ROBUSTNESS.**

All periodic and manual outcomes intended for the Auto-Download Status UI are recorded through `AutoDownloadService` and `AutoDownloadActivity`. The Job must not maintain a separate transient activity representation.

Historical Angular activity was effectively one terminal status per episode path, whereas the current Service can create multiple filter rows for one episode/result sequence. PR09 must avoid unbounded per-result activity amplification that can crowd the latest-100 status window. Exact UI/history redesign is outside this RFC, but the acceptance test must prove a bounded number of activity rows per processed episode.

Preserve, for now:

- status UI reads bounded to the latest 100 records;
- current 30-day retention intent.

PR10 makes pruning lifecycle-safe. A fixed weekly wall-clock tick is insufficient for a desktop app that may be closed at that instant; pruning needs catch-up/idempotent semantics.

### 12. Settings UI and server validation must match canonical units

**Classification: BUG FIX.**

Before periodic activation:

- use `torrenting.autodownload`, not `autodownload.enabled`;
- display `autodownload.period` as lookback days, not cadence hours;
- enforce integer range `1–21` server-side;
- persist `autodownload.delay` as integer minutes;
- permit UI presentation of delay as `days hours:minutes`, but convert explicitly to/from stored minutes;
- enforce `0 <= delay <= periodDays * 24 * 60`;
- treat `customDelay` the same way: stored minutes or `null`, bounded by the active period.

Changing the 15-minute cadence remains outside this settings surface.

## Implementation sequence

### PR09 — canonical business/result contract remediation

PR09 does **not** wire the periodic scheduler, but it is not behaviorally inert: because the manual endpoint already reaches `AutoDownloadService`, fixing the Service can make the manual path work where it is currently broken. This user-visible effect must be explicitly tested and reported.

PR09 scope:

1. Fix persisted `Serie` names **and boolean/null semantics**.
2. Replace string size transport with typed `sizeBytes` and decimal-MB threshold conversion.
3. Replace impossible/raw-unit size tests with engine-boundary and downstream numeric regressions.
4. Centralize BTIH derivation, add base32 parity, and persist hash after successful launch.
5. Fix torrent-URL identity handling so no undefined `infoHash` key is read.
6. Implement historical periodic remote-membership guard using the connected client's torrent set.
7. Restore periodic connected-client prerequisite and ensure no checkpoint advance without it.
8. Preserve the historical periodic delay clamp.
9. Separate explicit manual eligibility from periodic eligibility; enforce only torrenting + aired/leaked + TVDB + usable-client prerequisites for the direct action.
10. Return manual success from the actual launch outcome, not magnetHash mutation.
11. Keep activity persistence solely in the Service and bound per-episode activity amplification.
12. Add persisted-database fixtures for all model contracts touched.

PR09 acceptance:

- no scheduler/lifecycle registration;
- manual path behavior change is explicitly tested and documented;
- a persisted visible/enabled series reaches search when otherwise eligible;
- a disabled periodic series is excluded;
- successful magnet launch persists canonical hash;
- repeated periodic check does not duplicate while hash is present remotely;
- removal from the remote client makes the episode eligible again subject to other gates;
- MB/GB/TB/KiB/MiB/GiB/TiB parser boundaries are numeric and deterministic;
- no machine-size formatting strings are used downstream;
- no second business implementation becomes authoritative.

### PR10 — periodic lifecycle integration

Only after PR09 is green and this RFC is owner-accepted.

1. Reduce `AutoDownloadJob` to a thin wrapper around the canonical Service.
2. Add bounded `ShouldBeUnique` dispatch uniqueness **and** bounded execution overlap protection.
3. Add a cross-platform cooperative scan deadline plus bounded outbound I/O.
4. Reconcile `retry_after`, job timeout, worker timeout, and lock expiries with that measured budget.
5. Wire prompt startup/re-enable/reconnect eligibility plus 15-minute recurring checks only when torrenting + periodic auto-download are enabled.
6. Implement and test the captured-`scanTo` checkpoint contract and offline catch-up.
7. Reconcile the auto-download settings UI and server validation with canonical keys/units.
8. Make activity pruning idempotent/catch-up-safe for desktop lifecycle.

PR10 acceptance:

- exactly one periodic business implementation is production-reachable;
- repeated scheduler ticks cannot create a backlog or concurrent full scans;
- no-client runs do not consume the checkpoint;
- Windows correctness does not depend on PCNTL;
- lifecycle behavior has explicit behavioral evidence; structural wiring alone is insufficient.

## Required regression matrix

PR09/PR10 together must cover at minimum:

### Persisted model semantics

- persisted `displaycalendar=true` passes the visibility gate;
- persisted `displaycalendar=false` is excluded periodically;
- persisted `autoDownload=false` excludes a series periodically;
- `ignoreHideSpecials` boolean semantics are correct;
- custom seeders/includes/excludes/quality/provider/size/delay values use canonical camelCase fields;
- `TVDB_ID`/snake_case shadow reads are absent from canonical Service logic.

### Manual path

- requires enabled torrenting;
- requires aired or leaked episode;
- requires TVDB ID;
- requires usable torrent client;
- does not require periodic auto-download enabled;
- does not require series `autoDownload` enabled;
- does not wait for periodic delay;
- explicit action may re-add a watched/downloaded/already-hashed episode under the accepted product decision;
- returns actual torrent launch success/failure.

### Size contract

- Generic parser emits numeric `sizeBytes`, never formatted numeric strings;
- decimal MB/GB/TB conversions use powers of 1000;
- KiB/MiB/GiB/TiB conversions use powers of 1024;
- thresholds use decimal MB × 1,000,000;
- exact minimum and maximum boundaries are inclusive;
- `null`/unknown/`n/a` size remains eligible;
- The Pirate Bay parser regression;
- 1337x parser regression;
- ShowRSS unknown-size regression;
- per-series min/max overrides supersede globals.

### Torrent identity / duplicate prevention

- 40-char hex magnet BTIH canonicalization;
- 32-char base32 BTIH conversion to canonical hex;
- successful magnet add persists hash;
- torrent-URL path establishes hash before tracked success;
- no undefined `infoHash` access;
- periodic scan skips when stored hash exists remotely;
- periodic scan may retry when stored hash is no longer present remotely;
- second periodic scan after a successful active torrent does not launch again.

### Period / delay / checkpoint

- period default 1 day and valid range 1–21;
- delay is stored in minutes;
- custom/global delay is clamped to period × 24 × 60;
- first run uses `scanTo - periodDays`, rounded to start of day;
- later run uses `lastRun - periodDays`, rounded to start of day;
- app-offline gap is covered after reopen;
- no connected client means no candidate scan and no checkpoint advance;
- checkpoint advances to captured `scanTo`, not completion time;
- aborted full scan does not advance checkpoint;
- per-episode failure does not incorrectly abort other eligible candidates unless the cooperative budget is exhausted.

### Lifecycle / queue

- prompt startup eligibility;
- re-enable trigger;
- client-reconnection trigger;
- 15-minute recurring cadence;
- duplicate dispatch suppression;
- execution overlap prevention including queue re-reservation scenario;
- bounded lock recovery after crash/expiry;
- overlap rejection does not exhaust attempts;
- cooperative deadline stops additional work cleanly;
- every periodic outbound path has bounded I/O;
- configuration asserts cooperative budget + margin < `retry_after`;
- PCNTL presence/absence does not change correctness invariants.

### Activity lifecycle

- periodic/manual activity is persisted only through the Service;
- bounded activity rows per episode/check;
- latest-100 status query remains bounded;
- 30-day pruning catches up after missed desktop schedule windows.

## Explicit non-goals

- changing the historical 15-minute cadence without separate product evidence/maintainer decision;
- changing existing stored size threshold numbers away from MB semantics;
- redesigning torrent ranking/seeders selection beyond defects required for the canonical contract;
- a React/Vue/Tauri rewrite;
- changing 30-day activity retention without evidence;
- wiring periodic Trakt updates (RFC B);
- claiming real desktop suspend/resume, crash recovery, or long-running timing behavior before execution artifacts exist.

## Approval gate

This RFC remains **Proposed** until owner acceptance.

PR09 must not start as implementation of an agreed contract until this revised RFC passes independent review and owner acceptance. PR10 must not activate periodic auto-download until PR09's persisted/model/result/identity regressions are green.
