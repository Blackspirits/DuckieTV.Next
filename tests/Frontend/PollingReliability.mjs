import fs from 'node:fs';
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
await new Promise((resolve) => setImmediate(resolve));
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
