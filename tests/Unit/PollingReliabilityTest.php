<?php

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
