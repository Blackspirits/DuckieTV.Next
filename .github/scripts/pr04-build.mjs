import fs from 'node:fs';

const packagePath = 'package.json';
const packageJson = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
if (packageJson.scripts?.dev) {
    delete packageJson.scripts.dev;
}
if (packageJson.scripts && Object.keys(packageJson.scripts).length === 0) {
    delete packageJson.scripts;
}
packageJson.devDependencies.concurrently = '^9.2.4';
fs.writeFileSync(packagePath, `${JSON.stringify(packageJson, null, 4)}\n`);

const composerPath = 'composer.json';
const composerJson = JSON.parse(fs.readFileSync(composerPath, 'utf8'));
composerJson.scripts.setup = [
    'composer install',
    '@php -r "file_exists(\'.env\') || copy(\'.env.example\', \'.env\');"',
    '@php artisan key:generate',
    '@php -r "file_exists(\'database/database.sqlite\') || touch(\'database/database.sqlite\');"',
    '@php artisan migrate --force',
    'npm ci',
];
composerJson.scripts.dev = [
    'Composer\\Config::disableProcessTimeout',
    'npx concurrently -c "#93c5fd,#c4b5fd,#fb7185" "php artisan serve" "php artisan queue:listen --tries=1 --timeout=0" "php artisan pail --timeout=0" --names=server,queue,logs --kill-others',
];
composerJson.scripts['native:dev'] = [
    'Composer\\Config::disableProcessTimeout',
    '@php artisan native:run --no-interaction',
];
fs.writeFileSync(composerPath, `${JSON.stringify(composerJson, null, 4)}\n`);

const workflowPath = '.github/workflows/Tests.yml';
const workflow = fs.readFileSync(workflowPath, 'utf8');
const installNeedle = '      - name: Install NPM dependencies\n        run: npm install\n';
if (!workflow.includes(installNeedle)) {
    throw new Error('canonical npm install step not found exactly once');
}
const updatedWorkflow = workflow.replace(installNeedle, '      - name: Install NPM dependencies\n        run: npm ci\n');
if (updatedWorkflow.includes('run: npm install')) {
    throw new Error('unexpected npm install command remains in Tests.yml');
}
fs.writeFileSync(workflowPath, updatedWorkflow);
