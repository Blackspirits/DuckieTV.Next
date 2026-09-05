from pathlib import Path

PREREQUISITE = 'eb53b7a43c9496854e0db3c0f7f5fa31a633e2ac'


def replace_exact(path, old, new, expected=1):
    file = Path(path)
    text = file.read_text()
    count = text.count(old)
    if count != expected:
        raise SystemExit(f'{path}: expected {expected} occurrence(s), found {count}')
    file.write_text(text.replace(old, new))


# 1. Put one explicit timeout policy behind the shared torrent-client base class.
base_path = Path('app/Services/TorrentClients/BaseTorrentClient.php')
base = base_path.read_text()
base = base.replace(
    'use App\\Services\\SettingsService;\n',
    'use App\\Services\\SettingsService;\nuse Illuminate\\Http\\Client\\PendingRequest;\nuse Illuminate\\Support\\Facades\\Http;\n',
    1,
)
base = base.replace(
    'abstract class BaseTorrentClient implements TorrentClientInterface\n{\n',
    'abstract class BaseTorrentClient implements TorrentClientInterface\n{\n    protected const CONNECT_TIMEOUT_SECONDS = 3;\n\n    protected const REQUEST_TIMEOUT_SECONDS = 8;\n\n',
    1,
)
marker = '''    protected function getConfigMappings(): array\n    {\n        return [];\n    }\n'''
helper = '''    protected function http(): PendingRequest\n    {\n        return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)\n            ->timeout(self::REQUEST_TIMEOUT_SECONDS);\n    }\n\n''' + marker
if base.count(marker) != 1:
    raise SystemExit('BaseTorrentClient.php: getConfigMappings marker mismatch')
base = base.replace(marker, helper, 1)
base_path.write_text(base)

http_drivers = [
    'Aria2Client.php',
    'DelugeClient.php',
    'KTorrentClient.php',
    'QBittorrentClient.php',
    'RTorrentClient.php',
    'TixatiClient.php',
    'TransmissionClient.php',
    'TTorrentClient.php',
    'UTorrentClient.php',
    'UTorrentWebUIClient.php',
]

replaced_http_calls = 0
for name in http_drivers:
    path = Path('app/Services/TorrentClients') / name
    text = path.read_text()
    if 'use Illuminate\\Support\\Facades\\Http;\n' not in text:
        raise SystemExit(f'{path}: expected Http facade import')
    text = text.replace('use Illuminate\\Support\\Facades\\Http;\n', '', 1)
    count = text.count('Http::')
    if count == 0:
        raise SystemExit(f'{path}: expected direct Http facade calls')
    replaced_http_calls += count
    text = text.replace('Http::', '$this->http()->')
    path.write_text(text)

if replaced_http_calls != 40:
    raise SystemExit(f'Expected to route 40 direct Http:: calls through base policy, got {replaced_http_calls}')

# 2. qBittorrent: safely reuse an authenticated SID across Laravel requests.
qb_path = Path('app/Services/TorrentClients/QBittorrentClient.php')
qb = qb_path.read_text()
qb = qb.replace(
    'use App\\Services\\SettingsService;\n',
    'use App\\Services\\SettingsService;\nuse Illuminate\\Contracts\\Encryption\\DecryptException;\nuse Illuminate\\Support\\Facades\\Cache;\nuse Illuminate\\Support\\Facades\\Crypt;\n',
    1,
)
qb = qb.replace(
    "class QBittorrentClient extends BaseTorrentClient\n{\n    /** @var string|null Authentication cookie */\n    protected ?string $cookie = null;\n",
    "class QBittorrentClient extends BaseTorrentClient\n{\n    private const SESSION_CACHE_TTL_SECONDS = 300;\n\n    /** @var string|null Authentication cookie */\n    protected ?string $cookie = null;\n\n    /** @var array<int, array<string, mixed>>|null */\n    protected ?array $torrentSnapshot = null;\n",
    1,
)
constructor_marker = '''    public function __construct(SettingsService $settings)\n    {\n        parent::__construct($settings);\n        $this->name = 'qBittorrent 4.1+';\n        $this->id = 'qbittorrent41plus';\n    }\n'''
constructor_replacement = constructor_marker + '''\n    public function readConfig(): void\n    {\n        parent::readConfig();\n        $this->connected = false;\n        $this->cookie = null;\n        $this->torrentSnapshot = null;\n    }\n'''
if qb.count(constructor_marker) != 1:
    raise SystemExit('QBittorrentClient.php: constructor marker mismatch')
qb = qb.replace(constructor_marker, constructor_replacement, 1)

start = qb.index('    public function connect(): bool\n')
end = qb.index('    /**\n     * Retrieve the list of torrents from qBittorrent.', start)
connect_and_cache = '''    public function connect(): bool\n    {\n        if ($this->connected && $this->cookie !== null) {\n            return true;\n        }\n\n        if ($this->cookie === null) {\n            $this->cookie = $this->loadCachedCookie();\n        }\n\n        if ($this->cookie !== null) {\n            $response = $this->http()\n                ->withHeaders(['Cookie' => $this->cookie])\n                ->get($this->getUrl('torrents/info'));\n\n            if ($response->successful()) {\n                $this->torrentSnapshot = $response->json() ?? [];\n                $this->connected = true;\n                $this->cacheCookie($this->cookie);\n\n                return true;\n            }\n\n            $this->forgetCachedCookie();\n            $this->cookie = null;\n        }\n\n        /** @var \\Illuminate\\Http\\Client\\Response $response */\n        $response = $this->http()->asForm()->post($this->getUrl('auth/login'), [\n            'username' => $this->config['username'],\n            'password' => $this->config['password'],\n        ]);\n\n        if ($response->successful() && $response->body() === 'Ok.') {\n            $this->cookie = $response->header('Set-Cookie');\n            $this->connected = true;\n\n            if ($this->cookie !== null) {\n                $this->cacheCookie($this->cookie);\n            }\n\n            return true;\n        }\n\n        $this->connected = false;\n        if (! $response->successful()) {\n            throw new Exception("qBittorrent returned HTTP {$response->status()}: ".$response->body());\n        }\n\n        throw new Exception('qBittorrent login failed: '.$response->body());\n    }\n\n    protected function sessionCacheKey(): string\n    {\n        $passwordFingerprint = hash_hmac(\n            'sha256',\n            (string) ($this->config['password'] ?? ''),\n            (string) config('app.key'),\n        );\n\n        $identity = serialize([\n            $this->config['server'] ?? '',\n            $this->config['port'] ?? '',\n            $this->config['username'] ?? '',\n            $passwordFingerprint,\n        ]);\n\n        return 'torrent:qbittorrent:sid:'.hash('sha256', $identity);\n    }\n\n    protected function loadCachedCookie(): ?string\n    {\n        $value = Cache::get($this->sessionCacheKey());\n        if (! is_string($value)) {\n            return null;\n        }\n\n        try {\n            return Crypt::decryptString($value);\n        } catch (DecryptException) {\n            Cache::forget($this->sessionCacheKey());\n\n            return null;\n        }\n    }\n\n    protected function cacheCookie(string $cookie): void\n    {\n        Cache::put(\n            $this->sessionCacheKey(),\n            Crypt::encryptString($cookie),\n            self::SESSION_CACHE_TTL_SECONDS,\n        );\n    }\n\n    protected function forgetCachedCookie(): void\n    {\n        Cache::forget($this->sessionCacheKey());\n    }\n\n'''
qb = qb[:start] + connect_and_cache + qb[end:]

start = qb.index('    public function getTorrents(): array\n')
end = qb.index('    /**\n     * Start a torrent by its infohash.', start)
get_torrents = '''    public function getTorrents(): array\n    {\n        if (! $this->connected && ! $this->connect()) {\n            return [];\n        }\n\n        if ($this->torrentSnapshot !== null) {\n            $data = $this->torrentSnapshot;\n            $this->torrentSnapshot = null;\n        } else {\n            /** @var \\Illuminate\\Http\\Client\\Response $response */\n            $response = $this->http()\n                ->withHeaders(['Cookie' => $this->cookie])\n                ->get($this->getUrl('torrents/info'));\n\n            if (! $response->successful()) {\n                $this->connected = false;\n                $this->forgetCachedCookie();\n\n                throw new Exception("qBittorrent returned HTTP {$response->status()}: ".$response->body());\n            }\n\n            $data = $response->json() ?? [];\n        }\n\n        return collect($data)->map(fn ($torrent) => new QBittorrentData([\n            'infoHash' => strtoupper($torrent['hash']),\n            'name' => $torrent['name'],\n            'progress' => (float) $torrent['progress'] * 100,\n            'dlspeed' => $torrent['dlspeed'],\n            'state' => $torrent['state'],\n        ]))->all();\n    }\n\n'''
qb = qb[:start] + get_torrents + qb[end:]
qb_path.write_text(qb)

# 3. Frontend: replace fixed setInterval with one bounded, visibility-aware loop.
poll_path = Path('public/js/PollingService.js')
poll = poll_path.read_text()
marker = '    handleStatusUpdate(data) {'
idx = poll.index(marker)
suffix = poll[idx:]
prefix = '''/**\n * Lightweight polling service for torrent client status.\n *\n * Keeps at most one request in flight, applies bounded exponential backoff\n * while disconnected/failing, and pauses new polling while the document is hidden.\n */\nclass PollingService {\n    constructor(interval = 2000, translations = {}, requestTimeout = 10000, maxBackoff = 30000) {\n        this.interval = interval;\n        this.translations = translations;\n        this.requestTimeout = requestTimeout;\n        this.maxBackoff = maxBackoff;\n        this.timer = null;\n        this.running = false;\n        this.inFlight = false;\n        this.abortController = null;\n        this.failureCount = 0;\n        this.csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');\n        this.visibilityHandler = () => this.handleVisibilityChange();\n    }\n\n    start() {\n        if (this.running) {\n            return;\n        }\n\n        this.running = true;\n        document.addEventListener?.('visibilitychange', this.visibilityHandler);\n\n        if (!document.hidden) {\n            void this.poll();\n        }\n\n        console.log('PollingService started.');\n    }\n\n    stop() {\n        this.running = false;\n\n        if (this.timer !== null) {\n            clearTimeout(this.timer);\n            this.timer = null;\n        }\n\n        if (this.abortController !== null) {\n            this.abortController.abort();\n            this.abortController = null;\n        }\n\n        document.removeEventListener?.('visibilitychange', this.visibilityHandler);\n        console.log('PollingService stopped.');\n    }\n\n    schedule(delay) {\n        if (!this.running || document.hidden || this.timer !== null) {\n            return;\n        }\n\n        this.timer = setTimeout(() => {\n            this.timer = null;\n            void this.poll();\n        }, delay);\n    }\n\n    handleVisibilityChange() {\n        if (document.hidden) {\n            if (this.timer !== null) {\n                clearTimeout(this.timer);\n                this.timer = null;\n            }\n            return;\n        }\n\n        if (this.running && !this.inFlight) {\n            this.failureCount = 0;\n            void this.poll();\n        }\n    }\n\n    backoffDelay() {\n        const exponent = Math.min(this.failureCount, 4);\n        return Math.min(this.maxBackoff, this.interval * (2 ** exponent));\n    }\n\n    emitMetric(startedAt, outcome, nextDelay) {\n        const now = globalThis.performance?.now?.() ?? Date.now();\n        document.dispatchEvent(new CustomEvent('torrent-poll-metric', {\n            detail: {\n                durationMs: Math.max(0, Math.round(now - startedAt)),\n                outcome,\n                nextDelayMs: nextDelay,\n            },\n        }));\n    }\n\n    async poll() {\n        if (!this.running || this.inFlight || document.hidden) {\n            return false;\n        }\n\n        this.inFlight = true;\n        const startedAt = globalThis.performance?.now?.() ?? Date.now();\n        const controller = new AbortController();\n        this.abortController = controller;\n        const timeout = setTimeout(() => controller.abort(), this.requestTimeout);\n        let outcome = 'error';\n        let nextDelay = this.interval;\n\n        try {\n            const response = await fetch('/torrents/status', {\n                headers: {\n                    'Accept': 'application/json',\n                    'X-CSRF-TOKEN': this.csrfToken,\n                },\n                signal: controller.signal,\n            });\n\n            if (!response.ok) {\n                throw new Error(`HTTP ${response.status}`);\n            }\n\n            const data = await response.json();\n            this.handleStatusUpdate(data);\n\n            if (data.connected) {\n                this.failureCount = 0;\n                outcome = 'connected';\n            } else {\n                this.failureCount += 1;\n                nextDelay = this.backoffDelay();\n                outcome = 'disconnected';\n            }\n\n            return true;\n        } catch (error) {\n            const stoppedAbort = error?.name === 'AbortError' && !this.running;\n            if (!stoppedAbort) {\n                console.warn('Polling failed:', error);\n                this.handleError(error);\n                this.failureCount += 1;\n                nextDelay = this.backoffDelay();\n                outcome = error?.name === 'AbortError' ? 'timeout' : 'error';\n            } else {\n                outcome = 'stopped';\n            }\n\n            return false;\n        } finally {\n            clearTimeout(timeout);\n            if (this.abortController === controller) {\n                this.abortController = null;\n            }\n            this.inFlight = false;\n            this.emitMetric(startedAt, outcome, nextDelay);\n\n            if (this.running && !document.hidden) {\n                this.schedule(nextDelay);\n            }\n        }\n    }\n\n'''
poll_path.write_text(prefix + suffix)

# 4. Regression tests: frontend behavior, shared HTTP policy, qBittorrent SID reuse.
Path('tests/Frontend/PollingReliability.mjs').write_text(r'''import fs from 'node:fs';
import vm from 'node:vm';

const listeners = new Map();
const metrics = [];
let hidden = false;
let nextTimerId = 1;
const timers = new Map();
let now = 100;

const actionbar = { classList: { toggle() {} }, querySelector() { return null; } };
const torrentLink = { classList: { toggle() {} } };

globalThis.performance = { now: () => now };
globalThis.document = {
    get hidden() { return hidden; },
    querySelector(selector) {
        if (selector === 'meta[name="csrf-token"]') {
            return { getAttribute: () => 'test-token' };
        }
        if (selector === '.torrent-client-link') {
            return torrentLink;
        }
        if (selector === '#actionbar-torrent') {
            return actionbar;
        }
        return null;
    },
    addEventListener(type, callback) { listeners.set(type, callback); },
    removeEventListener(type, callback) {
        if (listeners.get(type) === callback) listeners.delete(type);
    },
    dispatchEvent(event) { if (event.type === 'torrent-poll-metric') metrics.push(event.detail); },
};
globalThis.CustomEvent = class CustomEvent {
    constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
};
globalThis.setTimeout = (callback, delay) => {
    const id = nextTimerId++;
    timers.set(id, { callback, delay });
    return id;
};
globalThis.clearTimeout = (id) => timers.delete(id);

const source = fs.readFileSync('public/js/PollingService.js', 'utf8');
vm.runInThisContext(`${source}\nglobalThis.__PollingService = PollingService;`);

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

function response(data) {
    return { ok: true, status: 200, json: async () => data };
}

function resetTimers() {
    timers.clear();
    nextTimerId = 1;
}

function scheduledDelayExcluding(delayToExclude) {
    return [...timers.values()].map((timer) => timer.delay).find((delay) => delay !== delayToExclude);
}

// Single in-flight request: a second poll attempt must not start another fetch.
resetTimers();
metrics.length = 0;
hidden = false;
const firstFetch = deferred();
let fetchCount = 0;
globalThis.fetch = async () => {
    fetchCount += 1;
    return firstFetch.promise;
};
const service = new globalThis.__PollingService(2000, {}, 10000, 30000);
service.running = true;
const firstPoll = service.poll();
if (fetchCount !== 1) throw new Error(`expected first fetch, got ${fetchCount}`);
const overlappingAttempt = await service.poll();
if (overlappingAttempt !== false || fetchCount !== 1) {
    throw new Error(`overlapping poll was not suppressed: result=${overlappingAttempt}, fetches=${fetchCount}`);
}
firstFetch.resolve(response({ connected: true, client: 'test', active_count: 0 }));
await firstPoll;
if (scheduledDelayExcluding(10000) !== 2000) throw new Error('connected poll did not schedule the base interval');
if (metrics.at(-1)?.outcome !== 'connected') throw new Error('connected timing metric missing');
service.stop();

// Hidden documents must not start polling; becoming visible resumes immediately.
resetTimers();
metrics.length = 0;
hidden = true;
let visibleFetchResolve;
fetchCount = 0;
globalThis.fetch = async () => {
    fetchCount += 1;
    return new Promise((resolve) => { visibleFetchResolve = resolve; });
};
const visibilityService = new globalThis.__PollingService(2000, {}, 10000, 30000);
visibilityService.start();
if (fetchCount !== 0) throw new Error('polling started while document was hidden');
hidden = false;
listeners.get('visibilitychange')?.();
if (fetchCount !== 1) throw new Error('polling did not resume when document became visible');
visibleFetchResolve(response({ connected: false, client: 'test', active_count: 0, error: 'offline' }));
await Promise.resolve();
await Promise.resolve();
await Promise.resolve();
if (scheduledDelayExcluding(10000) !== 4000) throw new Error('first disconnected response did not back off to 4000 ms');
visibilityService.stop();

// A hanging fetch must be aborted at the request budget and then back off.
resetTimers();
metrics.length = 0;
hidden = false;
globalThis.fetch = (_url, options) => new Promise((_resolve, reject) => {
    options.signal.addEventListener('abort', () => {
        const error = new Error('aborted');
        error.name = 'AbortError';
        reject(error);
    });
});
const timeoutService = new globalThis.__PollingService(2000, {}, 10000, 30000);
timeoutService.running = true;
const timedPoll = timeoutService.poll();
const timeoutEntry = [...timers.entries()].find(([, timer]) => timer.delay === 10000);
if (!timeoutEntry) throw new Error('request timeout was not scheduled');
timeoutEntry[1].callback();
await timedPoll;
if (metrics.at(-1)?.outcome !== 'timeout') throw new Error('timeout timing metric missing');
if (scheduledDelayExcluding(10000) !== 4000) throw new Error('timed-out request did not back off');
timeoutService.stop();

console.log('polling-reliability: ok');
''')

Path('tests/Unit/PollingReliabilityTest.php').write_text(r'''<?php

use Symfony\Component\Process\Process;

it('keeps torrent polling bounded, non-overlapping, and visibility aware', function () {
    $root = dirname(__DIR__, 2);
    $process = new Process(['node', 'tests/Frontend/PollingReliability.mjs'], $root);
    $process->setTimeout(30);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException($process->getErrorOutput().$process->getOutput());
    }

    expect(trim($process->getOutput()))->toContain('polling-reliability: ok');
});
''')

Path('tests/Unit/Services/TorrentClientHttpPolicyTest.php').write_text(r'''<?php

it('routes torrent client HTTP calls through the bounded base request policy', function () {
    $root = dirname(__DIR__, 3);
    $base = file_get_contents($root.'/app/Services/TorrentClients/BaseTorrentClient.php');

    expect($base)
        ->toContain('CONNECT_TIMEOUT_SECONDS = 3')
        ->toContain('REQUEST_TIMEOUT_SECONDS = 8')
        ->toContain('Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)')
        ->toContain('->timeout(self::REQUEST_TIMEOUT_SECONDS)');

    $drivers = [
        'Aria2Client.php',
        'DelugeClient.php',
        'KTorrentClient.php',
        'QBittorrentClient.php',
        'RTorrentClient.php',
        'TixatiClient.php',
        'TransmissionClient.php',
        'TTorrentClient.php',
        'UTorrentClient.php',
        'UTorrentWebUIClient.php',
    ];

    foreach ($drivers as $driver) {
        $source = file_get_contents($root.'/app/Services/TorrentClients/'.$driver);
        expect($source, $driver)->not->toContain('Http::');
    }
});
''')

Path('tests/Unit/Services/QBittorrentSessionReuseTest.php').write_text(r'''<?php

use App\Services\SettingsService;
use App\Services\TorrentClients\QBittorrentClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

function qbSettingsMock(): SettingsService
{
    $values = [
        'qbittorrent32plus.server' => 'http://127.0.0.1',
        'qbittorrent32plus.port' => 8080,
        'qbittorrent32plus.use_auth' => true,
        'qbittorrent32plus.username' => 'duckie',
        'qbittorrent32plus.password' => 'secret',
    ];

    $settings = Mockery::mock(SettingsService::class);
    $settings->shouldReceive('get')->andReturnUsing(
        fn (string $key, mixed $default = null) => $values[$key] ?? $default,
    );

    return $settings;
}

function qbSessionCacheKey(): string
{
    $passwordFingerprint = hash_hmac('sha256', 'secret', (string) config('app.key'));
    $identity = serialize(['http://127.0.0.1', 8080, 'duckie', $passwordFingerprint]);

    return 'torrent:qbittorrent:sid:'.hash('sha256', $identity);
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
});

afterEach(function () {
    Mockery::close();
});

it('reuses an encrypted cached SID across client instances and consumes the verification snapshot', function () {
    Http::fakeSequence()
        ->push('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/; HttpOnly'])
        ->push([], 200)
        ->push([[
            'hash' => 'abcdef',
            'name' => 'Example',
            'progress' => 0.5,
            'dlspeed' => 123,
            'state' => 'downloading',
        ]], 200);

    $first = new QBittorrentClient(qbSettingsMock());
    expect($first->connect())->toBeTrue();
    expect($first->getTorrents())->toBeArray()->toHaveCount(0);

    $cached = Cache::get(qbSessionCacheKey());
    expect($cached)->toBeString()->not->toContain('SID=abc123');
    expect(Crypt::decryptString($cached))->toContain('SID=abc123');

    $second = new QBittorrentClient(qbSettingsMock());
    expect($second->connect())->toBeTrue();
    expect($second->getTorrents())->toHaveCount(1);

    Http::assertSentCount(3);
    $recorded = Http::recorded();
    $loginCount = collect($recorded)->filter(
        fn (array $entry) => str_contains($entry[0]->url(), '/auth/login'),
    )->count();
    expect($loginCount)->toBe(1);
    expect($recorded[2][0]->hasHeader('Cookie', 'SID=abc123; path=/; HttpOnly'))->toBeTrue();
});

it('falls back to a fresh login when a cached SID is rejected', function () {
    Cache::put(qbSessionCacheKey(), Crypt::encryptString('SID=stale'), 300);

    Http::fakeSequence()
        ->push('', 403)
        ->push('Ok.', 200, ['Set-Cookie' => 'SID=fresh; path=/; HttpOnly']);

    $client = new QBittorrentClient(qbSettingsMock());
    expect($client->connect())->toBeTrue();
    expect(Crypt::decryptString(Cache::get(qbSessionCacheKey())))->toContain('SID=fresh');
    Http::assertSentCount(2);
});
''')
