<?php

namespace App\Services;

use Native\Desktop\Contracts\ChildProcess as ChildProcessContract;

class LongQueueWorkerService
{
    public const CONNECTION = 'database_long';

    public const QUEUE = 'default';

    public function __construct(
        private readonly ChildProcessContract $childProcess,
    ) {}

    public function start(): void
    {
        $command = app()->isLocal() ? 'queue:listen' : 'queue:work';

        $this->childProcess->artisan(
            [
                $command,
                self::CONNECTION,
                '--name=default-long',
                '--queue='.self::QUEUE,
                '--memory=128',
                '--timeout=300',
                '--sleep=3',
                '--quiet',
            ],
            'queue_default_long',
            persistent: true,
            iniSettings: [
                'memory_limit' => '128M',
            ],
        );
    }
}
