# RFC A — Canonical auto-download implementation and desktop lifecycle

Status: **Proposed — revised after final independent review**  
Audit findings: **DTV-003, DTV-005, DTV-018**  
Base: PR08 `681a5fafab165f5f11a1b87d52e551d65dc28420`  
Upstream classification: **RFC_REQUIRED before code**

## Purpose

DuckieTV.Next currently has two materially different auto-download business implementations (`AutoDownloadJob` and `AutoDownloadService`) and no established production periodic wiring for either one. The implementation already reachable from the UI (`AutoDownloadService`) also contains live schema/type, result-size, torrent-identity, eligibility, client-lifecycle, and checkpoint defects.

This RFC defines the proposed canonical behavior that the PR09 workstream and PR10 must implement. It is an audit proposal, not an upstream maintainer decision and not authorization to merge, release, or publish code.

## Decision classification

Every behavioral decision is classified as one of:

- **HISTORICAL COMPATIBILITY** — directly supported by DuckieTV Angular behavior;
- **BUG FIX** — intentionally corrects a defect in DuckieTV Angular or DuckieTV.Next while preserving user-facing setting meaning where possible;
- **ROBUSTNESS** — strengthens lifecycle/error behavior without changing intended product semantics;
- **PRODUCT DECISION** — cannot be derived unambiguously from historical behavior and therefore requires explicit owner acceptance.

## Evidence boundary

The proposal is based on:

- static inspection of DuckieTV.Next at exact base `681a5fafab165f5f11a1b87d52e551d65dc28420`;
- comparison with DuckieTV Angular at `597eae17538b6b870ad790ee7ee9ac59b1c5363d`;
- deterministic PHP/framework semantics where explicitly stated;
- Laravel 12.51.0 source/docs, PHP PCNTL platform constraints, and NativePHP Desktop v2 source/docs;
- independent adversarial reviews followed by separate adjudication against the exact repository state.

No real periodic auto-download run, suspend/resume experiment, crash-recovery run, long-running queue benchmark, hostile-network run, real torrent download, live torrent-engine scrape, or model/evidence run is claimed.

### Reproducible source anchors

DuckieTV.Next base:

- `app/Jobs/AutoDownloadJob.php`
- `app/Services/AutoDownloadService.php`
- `app/Services/TorrentClientService.php`
- `app/Services/TorrentClients/BaseTorrentClient.php`
- `app/Providers/TorrentServiceProvider.php`
- `app/Services/TorrentSearchService.php`
- `app/Services/TorrentSearchEngines/GenericSearchEngine.php`
- `app/Services/TorrentSearchEngines/ThePirateBayEngine.php`
- `app/Services/TorrentSearchEngines/OneThreeThreeSevenXEngine.php`
- `app/Services/TorrentSearchEngines/ShowRSSEngine.php`
- `app/Support/MagnetUri.php`
- `app/Services/SettingsService.php`
- `app/Http/Controllers/EpisodeController.php`
- `app/Http/Controllers/TorrentController.php`
- `app/Http/Controllers/AutoDLStatusController.php`
- `app/Http/Requests/Settings/UpdateAutoDownloadSettingsRequest.php`
- `resources/views/torrents/results.blade.php`
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
- `js/services/TorrentSearchEngines/ThePirateBay.js`
- `js/controllers/settings/SettingsTorrentCtrl.js`
- `js/controllers/settings/SerieSettingsCtrl.js`
- `js/controllers/sidepanel/SidepanelEpisodeCtrl.js`
- `templates/sidepanel/episode-details.html`
- `templates/settings/auto-download.html`
- `js/utility.js`

Framework references:

- Laravel 12 queues: https://laravel.com/docs/12.x/queues
- Laravel 12 scheduler: https://laravel.com/docs/12.x/scheduling
- PHP PCNTL: https://www.php.net/pcntl
- NativePHP Desktop v2 queues: https://nativephp.com/docs/desktop/2/digging-deeper/queues

## Current-state observations

### Two business engines

`AutoDownloadJob` contains candidate selection, eligibility checks, search/filter logic, torrent launch logic, info-hash extraction, delay clamping, and last-run updates. `AutoDownloadService` independently implements much of the same domain behavior and is already used by manual/status surfaces.

Keeping both authoritative would make parity and future maintenance non-deterministic.

### The canonical-Service candidate is currently functionally broken

`Serie` casts `displaycalendar`, `autoDownload`, `ignoreHideSpecials`, `ignoreGlobalQuality`, `ignoreGlobalIncludes`, and `ignoreGlobalExcludes` to booleans.

`AutoDownloadService` mixes snake_case reads with strict integer comparisons. For example, `displaycalendar !== 1` rejects Eloquent boolean `true`. Renaming `auto_download` to `autoDownload` without changing comparison semantics would still be wrong.

This is a live contract defect, not naming style.

### Search-result size is heterogeneous and unsafe

The search layer has no single machine-size contract:

- `GenericSearchEngine::sizeToMB()` emits human-formatted strings such as `"1,500.00 MB"`;
- `AutoDownloadService::filterBySize()` casts the first whitespace token to `float`, so `(float) "1,500.00"` becomes `1.0`;
- `ShowRSSEngine` emits `"n/a"`;
- The Pirate Bay and 1337x transform source text before generic parsing;
- the current Job reads a separate `size_bytes` key;
- the torrent-results Blade view reads `$result['size']` directly.

The existing unit test that injects raw `"1.5 GB"` directly into `filterBySize()` does not model the generic production path and must not be treated as parity evidence.

Global size defaults are `null`. `SettingsService::get()` returns those configured defaults before any call-site fallback, so `get('torrenting.global_size_max', 10000)` still yields `null`. `null` historically means unbounded, not zero.

### Torrent identity persistence and comparison are inconsistent

`AutoDownloadService::download()` persists `Episode::magnetHash` only when a result already contains `infoHash`, while the generic result shape does not create that field. The torrent-URL path may index an undefined `infoHash` key.

Hash representation is also inconsistent:

- `MagnetUri::extractInfoHash()` currently returns uppercase hex;
- `AutoDownloadJob` has separate/incomplete hash extraction logic;
- `AutoDownloadService` lowercases stored/remote hashes in some paths;
- `TorrentController` may persist `MagnetUri` output unchanged;
- `EpisodeController` performs case-sensitive equality when matching stored and remote hashes.

DuckieTV Angular accepted hex or base32 BTIH and canonicalized identity before persistence/tracking.

### Periodic client reachability is process-local and currently undefined

`BaseTorrentClient::$connected` starts `false` on every fresh client instance. `TorrentClientService` resolves a configured registered client, but that does not imply the client is connected in the current PHP process.

The current `AutoDownloadService::check()` reads `isConnected()` and does not itself establish the connection before deciding whether remote torrents can be used. That is unsafe for queued execution, where the worker process must not rely on connection state established elsewhere.

DuckieTV Angular maintained client state in the long-lived browser process and explicitly used `AutoConnect()` around auto-download work. DuckieTV.Next therefore needs an operational current-process definition of a usable client rather than a cached boolean assumption.

### Existing-torrent semantics have drifted

DuckieTV Angular skipped a periodic candidate only when a stored `magnetHash` was also present in the connected client's torrent set. DuckieTV.Next currently skips on stored hash alone, even after the torrent is removed remotely.

### No production periodic wiring

`routes/console.php` schedules only `PruneAutoDLActivitiesJob`. No production schedule/dispatch of `AutoDownloadJob` or production lifecycle caller of `AutoDownloadService::check()` is established.

### Migrated settings UI conflicts with the original contract

Canonical historical settings are:

- `torrenting.enabled`: torrenting master switch;
- `torrenting.autodownload`: periodic auto-download switch;
- `autodownload.period`: lookback/overlap **days**, default `1`, range `1–21`;
- `autodownload.delay`: delay in **minutes**, default `15`;
- per-series `customDelay`: minutes or `null`;
- recurring cadence: **15 minutes**, independent of `autodownload.period`.

The current Blade settings surface mislabels `period`, uses conflicting keys/defaults, and server validation does not enforce the full canonical unit/range contract.

### Queue timing is not portable as currently described

The database queue has `retry_after = 90`. NativePHP declares worker timeout `300`, while `AutoDownloadJob` declares `$timeout = 1800`. Laravel job-level timeout can override worker timeout, but Laravel's timeout enforcement requires PCNTL and PCNTL is unavailable on Windows.

`retry_after` can make a still-running database job reservable again. Therefore cross-platform correctness cannot depend on Laravel timeout alone, and execution overlap must be protected independently.

## Decision

### 1. `AutoDownloadService` becomes the sole business implementation

**Classification: ROBUSTNESS / maintainability.**

`AutoDownloadService` becomes the canonical application/domain implementation because it is already shared by manual/status surfaces and owns persistent `AutoDownloadActivity` recording.

`AutoDownloadJob` becomes a thin queued orchestrator only. Before duplicated Job logic is deleted, the PR09 workstream must inventory and migrate behavior that exists only or more correctly there, including:

- persisted camelCase attributes and boolean semantics;
- custom-delay clamp against `periodDays × 24 × 60`;
- info-hash derivation/persistence, including removal of incomplete duplicate base32 logic;
- per-series seeders/includes/excludes/provider/size/delay overrides;
- torrent-client success ordering.

No business behavior disappears merely because the Job is reduced.

### 2. Periodic and explicit manual download have different eligibility contracts

#### Periodic check

**Classification: HISTORICAL COMPATIBILITY, with server-side/process-local revalidation as ROBUSTNESS.**

A periodic full check requires:

- `torrenting.enabled = true`;
- `torrenting.autodownload = true`;
- an active configured torrent client that can be made usable in the **current process**;
- series `autoDownload = true`;
- calendar visibility;
- global specials policy with per-series override;
- episode aired state;
- runtime + effective delay elapsed;
- not already downloaded;
- not already watched;
- TVDB ID available;
- if `magnetHash` exists, skip only when that identity is currently present in the connected remote client.

#### Operational definition of a usable torrent client

**Classification: HISTORICAL COMPATIBILITY adapted to queued PHP lifecycle / ROBUSTNESS.**

A client is **usable** only after the auto-download execution path establishes or verifies connectivity in the current PHP process.

Operational contract:

1. Resolve the configured active client through `TorrentClientService`.
2. If no registered client can be resolved, the scan is not runnable.
3. Do not treat a newly constructed client's `isConnected()` flag as proof of reachability.
4. Before reading remote torrents or processing periodic candidates, call the client's `connect()` (or a deliberately equivalent current-process connection method) and require success.
5. Only after successful current-process connection may the scan call `getTorrents()` and evaluate candidates.
6. A failed connection attempt means: no candidate scan, no checkpoint advance, and an operational diagnostic/log event; it must not be represented as a successful empty scan.

The recurring/reconnect trigger may cause a new job to be dispatched, but each worker execution still establishes its own usable client state. Correctness must not depend on connection state retained by another request, browser window, scheduler process, or prior queue job.

Periodic delay:

```text
effectiveDelayMinutes = customDelay ?? autodownload.delay
effectiveDelayMinutes = min(effectiveDelayMinutes, periodDays * 24 * 60)
```

The delay clamp is historical compatibility.

#### Explicit manual episode download

**HISTORICAL COMPATIBILITY:** the original side-panel action called search/download directly. It did not depend on periodic `torrenting.autodownload`, series `autoDownload`, periodic delay, calendar/specials visibility, watched/downloaded state, or existing hash state.

The original UI exposed it only when:

- `torrenting.enabled = true`;
- episode had aired or was marked leaked;
- series had a TVDB ID.

**ROBUSTNESS:** the PR09 workstream enforces those reachability prerequisites server-side and requires a client made usable in the current process.

**PRODUCT DECISION PD-1 — requires explicit owner acceptance:** retain direct-action semantics after those prerequisites. Manual action may intentionally re-add an episode already marked watched/downloaded or already carrying a stored hash. It still uses canonical result filters and reports actual client launch success.

Consequence of accepting PD-1: a manual re-add can create more than one torrent for the same episode. Because `Episode::magnetHash` stores one identity, a successful re-add replaces the tracked identity with the new torrent hash. Any older torrent for that episode can remain active in the client but is no longer the episode's canonical tracked torrent. This is compatible with the historical direct-action model but must be accepted knowingly.

`manualDownload()` must not infer success from `magnetHash` mutation.

### 3. Persisted `Serie` names and value semantics are canonical

**Classification: BUG FIX.**

Relevant persisted/model fields include:

- `displaycalendar`: boolean;
- `autoDownload`: boolean;
- `ignoreHideSpecials`: boolean;
- `ignoreGlobalQuality`: boolean;
- `ignoreGlobalIncludes`: boolean;
- `ignoreGlobalExcludes`: boolean;
- `customSeeders`: nullable integer;
- `customIncludes`: nullable string;
- `customExcludes`: nullable string;
- `searchProvider`: nullable string;
- `customSearchSizeMin`: nullable integer, decimal-MB user semantics;
- `customSearchSizeMax`: nullable integer, decimal-MB user semantics;
- `customDelay`: nullable integer, minutes;
- `runtime`: nullable integer, minutes;
- `dlPath`: nullable text;
- `tvdb_id`: nullable integer.

The canonical Service removes shadow snake_case/legacy reads such as `auto_download`, `custom_seeders`, `custom_includes`, `search_provider`, `TVDB_ID`, etc. rather than introducing aliases.

Boolean fields are evaluated as booleans, not strict integer sentinels. Persisted fixtures must exercise Eloquent casts. Required regression: persisted `displaycalendar=true` passes the visibility gate.

### 4. Torrent-result size becomes a typed machine contract without breaking presentation

**Classification: BUG FIX.**

Canonical machine field:

```text
sizeBytes: ?int
```

Machine values must never be produced with `number_format()` or locale/display formatting.

Canonical units:

```text
1 KB  = 1,000 bytes
1 MB  = 1,000,000 bytes
1 GB  = 1,000,000,000 bytes
1 TB  = 1,000,000,000,000 bytes
1 KiB = 1,024 bytes
1 MiB = 1,048,576 bytes
1 GiB = 1,073,741,824 bytes
1 TiB = 1,099,511,627,776 bytes
```

Existing global/per-series threshold numbers keep **decimal MB** meaning.

Threshold semantics are explicitly null-safe:

```text
minMB = customSearchSizeMin ?? globalSizeMin
maxMB = customSearchSizeMax ?? globalSizeMax

minBytes = (minMB ?? 0) * 1,000,000
maxBytes = maxMB === null ? PHP_INT_MAX : maxMB * 1,000,000
```

Thus default `null` global thresholds mean **unbounded**, never zero. Exact minimum and maximum boundaries are inclusive.

#### Unknown size versus parser failure

Historical/declared unknown size remains eligible:

- source field absent;
- source field `null`;
- an engine's explicit unknown marker such as `n/a`.

These map to `sizeBytes = null`.

A non-empty source size that is expected to carry a magnitude/unit but cannot be parsed is **not** silently reclassified as historical unknown. That is an engine parser failure. For automatic download, that result is not size-eligible and the failure is recorded in diagnostic evidence/logging. Manual search presentation may still show an explicit unavailable/parse-error state, but business filtering must not treat malformed known-size text as `null`-unknown eligibility.

The canonical machine field does not remove human presentation. `resources/views/torrents/results.blade.php` renders human-readable size derived from `sizeBytes` (and `n/a` for declared unknown). No formatted presentation field is consumed by filtering logic. During migration, any legacy `size` field is presentation-only and must not remain an authoritative numeric input.

`AutoDownloadJob`'s separate `size_bytes` read is removed when the Job stops owning business logic.

#### Engine parsing and fixture provenance

Normalization happens at each result/parser boundary before downstream filtering.

Engine regressions must exercise **source-shaped fixtures**, not downstream strings invented solely for the filter under test. Where captured or historical engine HTML/JSON is available, preserve the relevant source fragment in a repository fixture. Synthetic fixtures must be labeled as such and reproduce the parser's actual source shape rather than bypassing the engine parser.

Whitespace-sensitive parsing must cover both ordinary ASCII whitespace and non-ASCII spacing that HTML sources may produce, including `U+00A0` between magnitude and unit. This is robustness coverage, not a claim that the current live TPB page was observed to emit NBSP during this audit.

For The Pirate Bay, the legacy parser does not preserve a reliable numeric-value-plus-unit pair: it selects a token from `.detDesc`. The PR09 size tranche must parse the original `.detDesc` source into **both magnitude and original unit** before conversion. If the source says `GiB`, it is converted using the IEC constant above; it must not be silently treated as decimal `GB`. This is a **BUG FIX**, not bug-compatible parity.

1337x and every other engine-specific parser that transforms source size before generic handling also require engine-level fixtures. ShowRSS keeps declared unknown size as `sizeBytes = null`.

The existing `filter_by_size_no_normalization_parity` test is replaced because its raw-unit downstream input does not represent the generic production flow.

The settings `torrenting.global_size_min_enabled` and `torrenting.global_size_max_enabled` historically affect torrent-dialog filtering, not auto-download filtering. The PR09 workstream must not silently introduce them as auto-download gates without a separate product decision.

### 5. Torrent identity has one canonical persisted/comparison form

**Classification: BUG FIX / HISTORICAL COMPATIBILITY.**

Canonical persisted BTIH representation is:

```text
40-character hexadecimal, lowercase
```

Inbound identity is case-insensitive. All reads/writes normalize before persistence or comparison. Existing/restored uppercase hashes remain compatible.

Canonical behavior:

1. `magnetUrl` with exactly 40 hexadecimal BTIH characters → lowercase 40-character hex.
2. 32-character base32 BTIH uses RFC 4648 alphabet `A–Z` and `2–7`, accepted case-insensitively; normalize base32 input to uppercase before decoding.
3. Base32 decode must produce the 20-byte BTIH value; encode as hexadecimal, left-pad with zeroes if necessary to exactly 40 hex characters, then lowercase.
4. Torrent URL/file path → obtain actual torrent info hash before tracked success.
5. Persist canonical lowercase hash only after the torrent client reports successful addition.
6. Periodic launch without reliable identity is not a fully tracked success and must not create a silent repeated-launch state.
7. No path indexes an undefined `infoHash` key.

`App\Support\MagnetUri` becomes the single magnet-identity helper and is completed for base32. Local regex/helpers in Job/Service are removed rather than allowed to drift.

The PR09 identity tranche normalizes all known consumers/writers, including at minimum:

- `AutoDownloadService`;
- `AutoDownloadJob` while it still exists;
- `TorrentController` persistence/response path;
- `EpisodeController` stored-vs-remote matching;
- remote torrent-map keys.

Required compatibility regression: an uppercase stored hash from an imported/legacy database matches a lowercase remote hash and does not trigger a duplicate periodic launch.

### 6. Existing-torrent checks use normalized remote membership, not stored hash alone

**Classification: HISTORICAL COMPATIBILITY.**

Periodic guard:

```text
stored = normalizeHash(episode.magnetHash)
remote = normalized set of active client hashes

if stored is present AND stored is in remote:
    STATUS_HAS_MAGNET and skip
else:
    continue normal eligibility/search
```

A stored hash whose torrent was removed remotely does not permanently suppress recovery.

This guard does not apply to explicit manual action under §2.

### 7. `autodownload.period` is lookback/overlap, not cadence

**Classification: HISTORICAL COMPATIBILITY with checkpoint ROBUSTNESS.**

Historical invariant:

- integer days;
- default `1`;
- range `1–21`;
- overlap before the prior checkpoint so recent missed episodes are reconsidered.

Proposed deterministic checkpoint:

```text
scanTo   = timestamp captured before querying candidates
anchor   = lastRun if present, otherwise scanTo
scanFrom = startOfDay(anchor - periodDays)
```

Advance `autodownload.lastrun` to captured `scanTo` only after normal scan completion.

Do not advance when:

- periodic auto-download disabled;
- torrenting disabled;
- no usable current-process client can be established;
- the full scan aborts before normal completion;
- the torrent client becomes unusable/lost during the scan.

Loss of the torrent client after the scan has started is an infrastructure-level abort, not an ordinary per-episode failure. Stop starting new candidate work and do not advance the checkpoint.

Per-episode failures unrelated to loss of required infrastructure may be recorded and processing may continue while budget remains. The overlap window keeps recent failed candidates eligible for later runs.

### 8. Recurring cadence remains fifteen minutes and reconnection is a trigger

**Classification: HISTORICAL COMPATIBILITY with scheduler adaptation as ROBUSTNESS.**

Recurring cadence is 15 minutes while the desktop app is active and is independent of `autodownload.period`.

Equivalent lifecycle triggers cover:

- prompt initial eligibility after startup when both feature switches are enabled;
- prompt eligibility after periodic auto-download is re-enabled;
- prompt eligibility when torrent client becomes connected/usable, matching historical `torrentclient:connected` intent.

A literal five-second JS timer is not required if NativePHP/Laravel provides equivalent prompt behavior.

A trigger is permission to dispatch/re-evaluate, not proof that a worker already owns a live client connection. Every periodic execution follows §2's current-process connection contract.

Offline/suspend gaps are recovered through persisted checkpoint + overlap, not replay of every missed tick.

### 9. Periodic queue work requires both dispatch uniqueness and execution overlap protection

**Classification: ROBUSTNESS.**

At most one periodic full-check job may be pending/running and at most one full scan may execute.

PR10 requires both:

1. **Dispatch uniqueness** — `ShouldBeUnique` or equivalent atomic dispatch lock with bounded `uniqueFor`.
2. **Execution overlap protection** — `WithoutOverlapping` or equivalent execution lock with bounded `expireAfter`, including database-queue re-reservation that bypasses dispatcher uniqueness.

They are complementary, not alternatives.

While `$tries = 1`, overlap rejection must not release into an immediate second attempt that then fails by exhausting attempts. `dontRelease()` or an explicitly tested equivalent is required unless attempt policy deliberately changes.

Lock lifetimes are finite and derived from §10's execution budget.

### 10. Cross-platform execution budget is cooperative; framework timeout is secondary

**Classification: ROBUSTNESS / correctness.**

PR10 establishes a cooperative full-scan deadline enforced inside orchestration. It is checked before each candidate and before additional expensive network/search work.

Every outbound operation reachable from periodic scan has narrower connect/request budgets.

Ordering:

```text
per-request connect/request budget < cooperative full-scan budget
cooperative full-scan budget + safety margin < queue retry_after
```

Where PCNTL exists, Laravel timeout remains a secondary kill safeguard. On Windows, correctness still comes from cooperative deadline + bounded I/O + execution lock.

`retry_after` is configured at queue-connection level, not per job. PR10 explicitly evaluates blast radius before changing the shared database connection. If the measured auto-download budget would materially worsen crash recovery for unrelated jobs, use a dedicated auto-download queue/connection with its own coherent `retry_after` rather than globally inflating the shared value.

The current `1800 / 300 / 90` declarations must not survive as contradictory active policy.

No numeric scan budget or final `retry_after` is chosen until timing evidence exists.

### 11. Activity persistence belongs to the canonical Service

**Classification: ROBUSTNESS with HISTORICAL status-shape compatibility.**

Periodic/manual outcomes intended for Auto-Download Status are recorded through `AutoDownloadService` and `AutoDownloadActivity`. Job-owned transient activity is removed.

Persistent activity target:

- at most one **terminal** `AutoDownloadActivity` row per episode per periodic scan or explicit manual attempt;
- ordinary internal diagnostics may use application logs/telemetry but must not amplify persistent activity per search result;
- the terminal status must preserve the historical stage that ended processing, rather than collapsing every filtered path to `STATUS_NOTHING_FOUND`.

Historical terminal-stage precedence for search/download is:

1. no scored search results → `STATUS_NOTHING_FOUND`;
2. required-keyword phase exhausts candidates → `STATUS_FILTERED_OUT` with `RK` context;
3. ignore-keyword phase exhausts candidates → `STATUS_FILTERED_OUT` with `IK` context;
4. size phase exhausts candidates → `STATUS_FILTERED_OUT` with `MS`/size context;
5. surviving top candidate fails seeders threshold → `STATUS_NOT_ENOUGH_SEEDERS`;
6. successful torrent-client addition → `STATUS_TORRENT_LAUNCHED`;
7. a client/search infrastructure failure uses a distinct diagnostic/failure outcome and must not masquerade as a normal empty result.

Eligibility exits before search retain their established status codes (`DOWNLOADED`, `WATCHED`, `HAS_MAGNET`, `AUTODL_DISABLED`, `ON_AIR_DELAY`, `TVDB_ID_MISSING`, etc.).

Preserve:

- latest-100 status read bound;
- current 30-day retention intent.

PR10 makes pruning idempotent/catch-up-safe for desktop lifecycle.

### 12. Settings UI and server validation match canonical units

**Classification: BUG FIX.**

Before periodic activation:

- use `torrenting.autodownload`, not `autodownload.enabled`;
- display `autodownload.period` as lookback days, not cadence hours;
- enforce integer `1–21` server-side;
- persist `autodownload.delay` as integer minutes;
- presentation may use `days hours:minutes`, with explicit conversion;
- enforce `0 <= delay <= periodDays * 24 * 60`;
- `customDelay` is minutes or `null`, bounded by active period.

The historical 15-minute cadence is not user-configured by `period`.

## Implementation sequence

The previous monolithic PR09 scope is deliberately split into a **PR09 workstream** to keep regressions reviewable and attribution clear. All tranches remain stacked after PR08/RFC A and none wires periodic scheduling.

### PR09a — persisted model semantics and fixtures

Scope:

1. Fix canonical `Serie` names, casts, boolean/null semantics, including `runtime`.
2. Remove canonical-Service legacy/snake_case reads instead of adding aliases.
3. Add persisted-database fixtures covering the touched Eloquent contracts.

Acceptance:

- persisted `displaycalendar=true` passes visibility logic;
- persisted `displaycalendar=false` is excluded periodically;
- persisted `autoDownload=false` is excluded periodically;
- custom fields use canonical persisted names/types;
- no scheduler/lifecycle registration.

### PR09b — typed size contract, engine parsers, and presentation

Scope:

1. Replace string machine-size transport with `sizeBytes`.
2. Implement null-safe decimal-MB threshold conversion.
3. Preserve human presentation in `resources/views/torrents/results.blade.php`.
4. Replace impossible downstream raw-unit tests with parser-boundary regressions.
5. Fix TPB source magnitude/unit parsing and cover 1337x/ShowRSS and other transforming engines.
6. Distinguish declared unknown size from malformed known-size parse failure.
7. Do not activate `global_size_*_enabled` flags for auto-download without a separate product decision.

Acceptance:

- default `null` thresholds are unlimited;
- exact boundaries are inclusive;
- decimal/SI and IEC conversions are deterministic;
- human results view remains populated;
- source-shaped fixtures cover engine parsing, including ASCII and `U+00A0` separator cases where applicable;
- non-empty malformed known-size text is not silently accepted as historical unknown;
- no machine-size formatting strings are consumed by business logic.

### PR09c — canonical BTIH identity and consumers

Scope:

1. Centralize BTIH parsing in `MagnetUri`.
2. Add exact hex and RFC 4648 base32 parity.
3. Normalize persistence/comparison to lowercase 40-char hex.
4. Normalize Service, Job, `TorrentController`, `EpisodeController`, and remote-map consumers.
5. Fix torrent-URL identity handling and undefined `infoHash` access.

Acceptance:

- 40-char hex canonicalizes to lowercase;
- lowercase/uppercase base32 decode identically;
- base32 result is exactly 20 bytes / 40 zero-padded hex characters;
- uppercase legacy stored hash matches lowercase remote hash;
- successful launch persists identity only after client success;
- repeated periodic check does not relaunch while identity remains remote.

### PR09d — canonical eligibility, client establishment, manual semantics, and activity

Scope:

1. Implement historical periodic normalized remote-membership guard.
2. Establish usable torrent client in the current process before periodic candidate work.
3. Preserve no-checkpoint-advance semantics on unavailable/lost client.
4. Preserve historical delay clamp.
5. Separate manual eligibility from periodic eligibility.
6. Return manual success from actual client launch result.
7. Consolidate persistent activity in Service with one terminal row and historical terminal-stage semantics.

Acceptance:

- a **fresh worker/process** with configured reachable client calls current-process connection establishment and reaches candidate processing;
- a failed current-process connection attempts no candidate scan and does not advance checkpoint;
- loss of client mid-scan aborts the full scan and does not advance checkpoint;
- removal of a stored torrent from remote makes the episode eligible again subject to other gates;
- manual path behavior is explicitly tested/documented;
- activity preserves useful terminal reason without per-result amplification;
- no scheduler/lifecycle registration.

### PR10 — periodic lifecycle integration

Only after PR09a–PR09d are green and this RFC is owner-accepted.

1. Reduce `AutoDownloadJob` to thin wrapper around canonical Service.
2. Add bounded dispatch uniqueness **and** bounded execution overlap protection.
3. Add cross-platform cooperative deadline + bounded outbound I/O.
4. Measure execution budget and reconcile queue/connection `retry_after`, job/worker timeout, and lock expiries; use dedicated queue/connection if required to avoid harming unrelated jobs.
5. Wire startup/re-enable/reconnect eligibility plus 15-minute recurring checks.
6. Implement/test captured-`scanTo` checkpoint and offline catch-up.
7. Reconcile auto-download settings UI/server validation.
8. Make activity pruning idempotent/catch-up-safe.

PR10 acceptance:

- exactly one periodic business implementation is production-reachable;
- repeated scheduler ticks cannot create backlog/concurrent scans;
- each worker establishes/verifies its own usable torrent client before candidate work;
- no-client and mid-scan client-loss runs do not consume checkpoint;
- Windows correctness does not depend on PCNTL;
- lifecycle behavior has behavioral evidence; structural wiring alone is insufficient.

## Required regression matrix

PR09a–PR09d and PR10 together cover at minimum:

### Persisted model semantics

- `displaycalendar=true` passes visibility;
- `displaycalendar=false` excluded periodically;
- `autoDownload=false` excluded periodically;
- `ignoreHideSpecials` boolean semantics;
- canonical camelCase custom seeders/includes/excludes/quality/provider/size/delay;
- `runtime` participates in effective on-air delay;
- legacy/snake_case shadow reads absent from canonical Service.

### Manual path

- requires enabled torrenting;
- requires aired or leaked episode;
- requires TVDB ID;
- establishes/requires usable current-process client;
- independent of periodic autodownload switch;
- independent of series `autoDownload`;
- independent of periodic delay;
- may re-add watched/downloaded/already-hashed episode only if PD-1 is explicitly accepted;
- returns actual client success/failure.

### Size contract

- Generic parser emits numeric `sizeBytes`;
- decimal MB/GB/TB use powers of 1000;
- KiB/MiB/GiB/TiB use powers of 1024;
- `minBytes = (minMB ?? 0) * 1,000,000`;
- `maxBytes = maxMB === null ? PHP_INT_MAX : maxMB * 1,000,000`;
- default global `null` thresholds mean unlimited;
- exact min/max boundaries inclusive;
- declared unknown size eligible;
- non-empty malformed known-size input is not silently accepted as unknown;
- engine tests run through engine/parser boundary, not downstream invented strings;
- TPB source-unit regression;
- ASCII and non-ASCII spacing around magnitude/unit covered, including `U+00A0` fixture case;
- 1337x parser regression;
- ShowRSS declared-unknown regression;
- per-series min/max override globals;
- human-readable results view remains populated from `sizeBytes`;
- no business logic consumes presentation `size` strings;
- auto-download does not start honoring `global_size_*_enabled` flags implicitly.

### Torrent identity / duplicate prevention

- 40-char hex input canonicalizes to lowercase;
- base32 accepts RFC 4648 `A–Z2–7` case-insensitively;
- base32 input is normalized before decode;
- decoded BTIH is exactly 20 bytes and hexadecimal output is left-zero-padded to 40 characters;
- successful magnet add persists canonical hash;
- torrent-URL path establishes hash before tracked success;
- no undefined `infoHash` access;
- uppercase stored legacy hash matches lowercase remote hash;
- `EpisodeController` matching is case-normalized;
- `TorrentController` persists canonical form;
- periodic scan skips when normalized stored hash exists remotely;
- periodic scan may retry when stored hash no longer remote;
- second periodic scan after active successful torrent does not launch again.

### Client establishment / period / delay / checkpoint

- a fresh worker/process starts with no assumed connection state;
- with configured reachable client, periodic execution calls `connect()`/equivalent in that process before remote reads/candidate work;
- failed connect => no candidate scan and no checkpoint advance;
- period default 1 day, range 1–21;
- delay stored minutes;
- custom/global delay clamped to period × 24 × 60;
- first run uses `scanTo - periodDays`, start-of-day;
- later run uses `lastRun - periodDays`, start-of-day;
- offline gap covered after reopen;
- checkpoint advances to captured `scanTo` only on normal full-scan completion;
- client loss during scan aborts and does not advance checkpoint;
- per-episode non-infrastructure failure does not abort others unless cooperative budget is exhausted.

### Activity lifecycle

- periodic/manual activity persisted only through Service;
- at most one terminal activity row per episode per scan/manual attempt;
- terminal reason follows historical stage precedence instead of collapsing all filtered paths;
- latest-100 query remains bounded;
- 30-day pruning catches up after missed desktop schedule windows.

### Lifecycle / queue

- startup eligibility;
- re-enable trigger;
- client-reconnection trigger;
- 15-minute cadence;
- each dispatched worker re-establishes current-process client usability;
- duplicate dispatch suppression;
- execution overlap prevention including queue re-reservation;
- bounded lock recovery;
- overlap rejection does not exhaust attempts;
- cooperative deadline stops new work cleanly;
- all periodic outbound paths have bounded I/O;
- cooperative budget + margin < applicable `retry_after`;
- shared-vs-dedicated queue/connection decision supported by configuration/timing evidence;
- PCNTL presence/absence does not change correctness invariants.

## Explicit non-goals

- changing historical 15-minute cadence without separate product evidence/maintainer decision;
- changing stored size threshold numbers away from MB semantics;
- making `global_size_*_enabled` switches apply to auto-download without separate product decision;
- redesigning torrent ranking/seeders selection beyond defects required for canonical contract;
- changing 30-day activity retention without evidence;
- wiring periodic Trakt updates (RFC B);
- claiming real desktop suspend/resume, crash recovery, live torrent-engine behavior, or long-running timing behavior before execution artifacts exist.

## Owner decision gate

This RFC remains **Proposed** until explicit owner acceptance.

Before acceptance, the owner must decide **PD-1**:

> Should an explicit manual episode download preserve historical direct-action semantics and therefore be allowed to re-add an episode already watched/downloaded/already carrying a tracked hash, accepting that a successful re-add replaces the single persisted `Episode::magnetHash` identity while an older torrent may remain active in the client?

Options:

- **A — preserve historical direct-action semantics** (RFC proposed default): allow re-add after the manual reachability prerequisites;
- **B — block re-add when already downloaded/watched/actively tracked**: diverges from historical direct-action behavior;
- **C — allow re-add only after an explicit user confirmation**: new product/UI behavior and outside the minimal PR09d contract unless separately approved.

No implementation should infer the owner's choice from historical behavior alone.

The PR09 workstream must not start as implementation of an agreed contract until this RFC passes final independent review/adjudication and the owner explicitly accepts the RFC plus PD-1. PR10 must not activate periodic auto-download until PR09a–PR09d regressions are green.