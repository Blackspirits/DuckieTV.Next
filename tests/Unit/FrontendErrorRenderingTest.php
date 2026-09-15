<?php

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
