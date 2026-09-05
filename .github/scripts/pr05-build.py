from pathlib import Path


def replace_exact(path, old, new, expected=1):
    file = Path(path)
    text = file.read_text()
    count = text.count(old)
    if count != expected:
        raise SystemExit(f'{path}: expected {expected} occurrence(s), found {count}')
    file.write_text(text.replace(old, new))


replace_exact(
    'public/js/PollingService.js',
    '''                const msg = panel.querySelector('.connecting-message strong');
                if (msg) msg.innerHTML = `<span class="text-danger">${data.error}</span>`;
''',
    '''                const msg = panel.querySelector('.connecting-message strong');
                if (msg) {
                    msg.textContent = '';
                    const errorText = document.createElement('span');
                    errorText.className = 'text-danger';
                    errorText.textContent = String(data.error);
                    msg.appendChild(errorText);
                }
'''
)

replace_exact(
    'public/js/SidePanel.js',
    '''    async show(url, options = {}) {
''',
    '''    renderError(error) {
        const message = error?.message ?? String(error);
        const alert = document.createElement('div');
        alert.className = 'alert alert-danger';
        alert.textContent = `Error: ${message}`;
        this.panel.replaceChildren(alert);
    }

    async show(url, options = {}) {
'''
)

replace_exact(
    'public/js/SidePanel.js',
    '''            this.panel.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
''',
    '''            this.renderError(error);
''',
    expected=2
)

replace_exact(
    'public/js/BackupRestore.js',
    '''                    const li = document.createElement('li');
                    li.innerHTML = `<strong>${this.failedSeries[i].time}</strong>: ${this.failedSeries[i].id} - ${this.failedSeries[i].error}`;
                    failuresList.appendChild(li);
''',
    '''                    const failure = this.failedSeries[i];
                    const li = document.createElement('li');
                    const time = document.createElement('strong');
                    time.textContent = String(failure.time ?? '');
                    li.appendChild(time);
                    li.appendChild(document.createTextNode(`: ${failure.id ?? ''} - ${failure.error ?? ''}`));
                    failuresList.appendChild(li);
'''
)

frontend = Path('tests/Frontend')
frontend.mkdir(parents=True, exist_ok=True)
frontend.joinpath('SafeErrorRendering.mjs').write_text(r'''import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root = new URL('../../', import.meta.url);
const payload = '<img src=x onerror="globalThis.__executed = true">';

class FakeTextNode {
    constructor(text) {
        this.textContent = String(text);
    }
}

class FakeElement {
    constructor() {
        this.children = [];
        this.className = '';
        this.dataset = {};
        this.style = {};
        this.title = '';
        this._textContent = '';
        this.query = () => null;
    }

    set innerHTML(value) {
        throw new Error(`unsafe innerHTML write: ${value}`);
    }

    get innerHTML() {
        return '';
    }

    set textContent(value) {
        this._textContent = String(value);
        this.children = [];
    }

    get textContent() {
        return this._textContent + this.children.map(child => child.textContent).join('');
    }

    appendChild(child) {
        this.children.push(child);
        return child;
    }

    replaceChildren(...children) {
        this._textContent = '';
        this.children = [...children];
    }

    querySelector(selector) {
        return this.query(selector);
    }
}

function source(path) {
    return fs.readFileSync(new URL(path, root), 'utf8');
}

function makeContext(document) {
    const context = {
        console,
        document,
        setInterval: () => 1,
        clearInterval: () => {},
        setTimeout: () => 1,
        clearTimeout: () => {},
        CustomEvent: class {},
        fetch: async () => { throw new Error('unexpected fetch'); },
    };
    context.window = context;
    context.globalThis = context;
    return vm.createContext(context);
}

// PollingService: torrent-client errors must remain literal text.
{
    const icon = new FakeElement();
    const message = new FakeElement();
    const connecting = new FakeElement();
    const panel = new FakeElement();
    panel.query = selector => {
        if (selector === '.connecting-message') return connecting;
        if (selector === '.connecting-message strong') return message;
        return null;
    };
    const meta = { getAttribute: () => 'csrf' };
    const document = {
        querySelector: selector => {
            if (selector === 'meta[name="csrf-token"]') return meta;
            if (selector === '#actionbar_torrent a') return icon;
            if (selector === '.torrent-client') return panel;
            return null;
        },
        createElement: () => new FakeElement(),
        dispatchEvent: () => {},
    };
    const context = makeContext(document);
    vm.runInContext(`${source('public/js/PollingService.js')}\nglobalThis.__PollingService = PollingService;`, context);
    const polling = new context.__PollingService(2000, {});
    polling.handleStatusUpdate({ connected: false, error: payload });
    assert.equal(message.children.length, 1);
    assert.equal(message.children[0].className, 'text-danger');
    assert.equal(message.children[0].textContent, payload);
    assert.equal(context.__executed, undefined);
}

// SidePanel: fetch error messages must be rendered as text, not markup.
{
    const document = { createElement: () => new FakeElement() };
    const context = makeContext(document);
    vm.runInContext(`${source('public/js/SidePanel.js')}\nglobalThis.__SidePanel = SidePanel;`, context);
    const panel = new FakeElement();
    const sidePanel = Object.create(context.__SidePanel.prototype);
    sidePanel.panel = panel;
    sidePanel.renderError({ message: payload });
    assert.equal(panel.children.length, 1);
    assert.equal(panel.children[0].className, 'alert alert-danger');
    assert.equal(panel.children[0].textContent, `Error: ${payload}`);
    assert.equal(context.__executed, undefined);
}

// BackupRestore: failure time/id/error fields must remain literal text.
{
    const failuresContainer = new FakeElement();
    const failuresList = new FakeElement();
    const modalRoot = new FakeElement();
    modalRoot.query = selector => {
        if (selector === '.restore-failures-container') return failuresContainer;
        if (selector === '.restore-failed-items') return failuresList;
        return null;
    };
    const document = {
        createElement: () => new FakeElement(),
        createTextNode: text => new FakeTextNode(text),
    };
    const context = makeContext(document);
    vm.runInContext(source('public/js/BackupRestore.js'), context);
    const backup = context.BackupRestore;
    backup.progressModal = { el: modalRoot };
    backup.isMinimized = false;
    backup.i18n = {};
    backup.posters = [];
    backup.failedSeries = [{ time: payload, id: payload, error: payload }];
    backup.lastFailedCount = 0;
    backup.updateDetailedUI({ status: 'running', percent: 10 });
    assert.equal(failuresList.children.length, 1);
    assert.equal(failuresList.children[0].textContent, `${payload}: ${payload} - ${payload}`);
    assert.equal(context.__executed, undefined);
}

console.log('safe-error-rendering: ok');
''')

Path('tests/Unit/FrontendErrorRenderingTest.php').write_text(r'''<?php

use Symfony\Component\Process\Process;

it('renders error-derived frontend strings as literal text', function () {
    $root = dirname(__DIR__, 2);
    $process = new Process(['node', 'tests/Frontend/SafeErrorRendering.mjs'], $root);
    $process->setTimeout(30);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException($process->getErrorOutput().$process->getOutput());
    }

    expect(trim($process->getOutput()))->toBe('safe-error-rendering: ok');
});
''')
