<?php

use Tests\TestCase;

uses(TestCase::class);

it('preserves the historical auto backup frontend contract', function () {
    $source = file_get_contents(public_path('js/BackupRestore.js'));

    expect($source)
        ->toContain('calculateNextAutoBackup: function (state)')
        ->toContain("case 'daily':")
        ->toContain("case 'weekly':")
        ->toContain("case 'monthly':")
        ->toContain('delay = 60000;')
        ->toContain('const maxTimerDelay = 24 * 60 * 60 * 1000;')
        ->toContain('showAutoBackupDialog: function ()')
        ->toContain("link.href = '/settings/backup/export';")
        ->toContain("'autobackup.lastrun': Date.now()")
        ->toContain('new MutationObserver')
        ->toContain("node.matches('#autoBackup, #nextAutoBackupDate')")
        ->toContain("fetch('/settings/autobackup/state', {")
        ->toContain("method: 'POST'");
});
