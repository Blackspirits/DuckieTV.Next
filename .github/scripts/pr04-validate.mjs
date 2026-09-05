import fs from 'node:fs';

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

function atLeast(actual, minimum) {
    const parse = (value) => value.split('.').map((part) => Number.parseInt(part, 10));
    const a = parse(actual);
    const b = parse(minimum);
    for (let i = 0; i < Math.max(a.length, b.length); i += 1) {
        const av = a[i] ?? 0;
        const bv = b[i] ?? 0;
        if (av > bv) return true;
        if (av < bv) return false;
    }
    return true;
}

const pkg = JSON.parse(fs.readFileSync('package.json', 'utf8'));
const composer = JSON.parse(fs.readFileSync('composer.json', 'utf8'));
const lock = JSON.parse(fs.readFileSync('package-lock.json', 'utf8'));
const testsWorkflow = fs.readFileSync('.github/workflows/Tests.yml', 'utf8');

assert(!pkg.scripts?.dev, 'package.json must not launch a duplicate dev process tree');
assert(pkg.devDependencies.concurrently === '^9.2.4', 'concurrently minimum must be ^9.2.4');
assert(pkg.devDependencies['laravel-echo'] === '^2.3.0', 'laravel-echo manifest range must remain unchanged');
assert(pkg.devDependencies['pusher-js'] === '^8.4.0', 'pusher-js manifest range must remain unchanged');
assert(pkg.devDependencies.tailwindcss === '^4.0.0', 'tailwindcss manifest range must remain unchanged');

const setup = composer.scripts.setup;
assert(Array.isArray(setup), 'composer setup must remain an array');
assert(setup.includes('npm ci'), 'composer setup must use npm ci');
assert(!setup.includes('npm install'), 'composer setup must not use npm install');
const sqliteStep = setup.findIndex((step) => step.includes("touch('database/database.sqlite')"));
const migrateStep = setup.findIndex((step) => step.includes('artisan migrate'));
assert(sqliteStep >= 0 && migrateStep > sqliteStep, 'composer setup must create SQLite DB before migrate');
assert(!setup.some((step) => step.includes('npm run build')), 'composer setup must not call an absent build script');

const dev = composer.scripts.dev.join('\n');
assert(dev.includes('php artisan serve'), 'composer dev must launch the Laravel server');
assert(dev.includes('php artisan queue:listen --tries=1 --timeout=0'), 'composer dev must launch one queue listener');
assert(dev.includes('php artisan pail --timeout=0'), 'composer dev must launch pail');
assert(dev.includes('--names=server,queue,logs'), 'composer dev process names must match three processes');
assert(!dev.includes('npm run dev'), 'composer dev must not recursively launch npm dev');
assert(!dev.includes('vite'), 'composer dev must not claim a Vite process');

const nativeDev = composer.scripts['native:dev'].join('\n');
assert(nativeDev.includes('php artisan native:run --no-interaction'), 'native:dev must launch NativePHP');
assert(!nativeDev.includes('npm run dev'), 'native:dev must not launch a nonexistent npm dev script');

assert(testsWorkflow.includes('run: npm ci'), 'canonical Tests workflow must use npm ci');
assert(!testsWorkflow.includes('run: npm install'), 'canonical Tests workflow must not use npm install');

const root = lock.packages[''];
assert(JSON.stringify(root.devDependencies) === JSON.stringify(pkg.devDependencies), 'package-lock root devDependencies must exactly match package.json');
assert(!root.devDependencies['@tailwindcss/vite'], 'stale @tailwindcss/vite root must be gone');
assert(!root.devDependencies['laravel-vite-plugin'], 'stale laravel-vite-plugin root must be gone');
assert(!root.devDependencies.vite, 'stale vite root must be gone');
assert(!lock.packages['node_modules/vite'], 'stale Vite closure must be gone');
assert(!lock.packages['node_modules/@tailwindcss/vite'], 'stale @tailwindcss/vite closure must be gone');
assert(!lock.packages['node_modules/laravel-vite-plugin'], 'stale laravel-vite-plugin closure must be gone');

const versions = {
    concurrently: lock.packages['node_modules/concurrently']?.version,
    'shell-quote': lock.packages['node_modules/shell-quote']?.version,
    'laravel-echo': lock.packages['node_modules/laravel-echo']?.version,
    'pusher-js': lock.packages['node_modules/pusher-js']?.version,
    tailwindcss: lock.packages['node_modules/tailwindcss']?.version,
};

for (const [name, version] of Object.entries(versions)) {
    assert(version, `${name} must exist in the resolved lock`);
}
assert(atLeast(versions.concurrently, '9.2.4'), `concurrently ${versions.concurrently} is too old`);
assert(atLeast(versions['shell-quote'], '1.9.0'), `shell-quote ${versions['shell-quote']} remains vulnerable`);
assert(versions['laravel-echo'] === '2.3.0', `laravel-echo drifted to ${versions['laravel-echo']}`);
assert(versions['pusher-js'] === '8.4.0', `pusher-js drifted to ${versions['pusher-js']}`);
assert(versions.tailwindcss === '4.1.18', `tailwindcss drifted to ${versions.tailwindcss}`);

for (const stalePeer of ['socket.io-client', 'engine.io-client', 'ws']) {
    assert(!lock.packages[`node_modules/${stalePeer}`], `${stalePeer} should not remain in the reconciled unused peer closure`);
}

console.log(JSON.stringify({
    versions,
    removedUnusedPeerClosure: ['socket.io-client', 'engine.io-client', 'ws'],
}, null, 2));
