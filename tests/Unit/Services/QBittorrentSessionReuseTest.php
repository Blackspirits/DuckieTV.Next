<?php

use App\Services\SettingsService;
use App\Services\TorrentClients\QBittorrentClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

function qbSettingsMock(): SettingsService
{
    $values = [
        'qbittorrent32plus.server' => 'http://127.0.0.1',
        'qbittorrent32plus.port' => 8080,
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

it('accepts the no-content login response used by current qBittorrent releases', function () {
    Http::fakeSequence()
        ->push('', 204, ['Set-Cookie' => 'SID=v52; path=/; HttpOnly']);

    $client = new QBittorrentClient(qbSettingsMock());

    expect($client->connect())->toBeTrue();
    expect($client->isConnected())->toBeTrue();
    expect(Crypt::decryptString(Cache::get(qbSessionCacheKey())))->toContain('SID=v52');
    Http::assertSentCount(1);
});

it('uses resume and pause endpoints for qBittorrent 4.x', function () {
    Http::fakeSequence()
        ->push('Ok.', 200, ['Set-Cookie' => 'SID=v4; path=/; HttpOnly'])
        ->push('v4.6.7', 200)
        ->push('', 200)
        ->push('', 200);

    $client = new QBittorrentClient(qbSettingsMock());

    expect($client->startTorrent('ABCDEF'))->toBeTrue();
    expect($client->pauseTorrent('ABCDEF'))->toBeTrue();

    $urls = collect(Http::recorded())->map(fn (array $entry) => $entry[0]->url())->all();
    expect($urls)->toHaveCount(4);
    expect($urls[1])->toContain('/api/v2/app/version');
    expect($urls[2])->toContain('/api/v2/torrents/resume');
    expect($urls[3])->toContain('/api/v2/torrents/pause');
});

it('uses start and stop endpoints for qBittorrent 5.x and reuses the version probe', function () {
    Http::fakeSequence()
        ->push('', 204, ['Set-Cookie' => 'SID=v5; path=/; HttpOnly'])
        ->push('v5.2.3', 200)
        ->push('', 204)
        ->push('', 204);

    $client = new QBittorrentClient(qbSettingsMock());

    expect($client->startTorrent('ABCDEF'))->toBeTrue();
    expect($client->stopTorrent('ABCDEF'))->toBeTrue();

    $urls = collect(Http::recorded())->map(fn (array $entry) => $entry[0]->url())->all();
    expect($urls)->toHaveCount(4);
    expect($urls[1])->toContain('/api/v2/app/version');
    expect($urls[2])->toContain('/api/v2/torrents/start');
    expect($urls[3])->toContain('/api/v2/torrents/stop');
});
