<?php

namespace Tests\Feature\Jobs;

use App\Services\LongQueueWorkerService;
use Mockery;
use Native\Desktop\Contracts\ChildProcess as ChildProcessContract;
use Tests\TestCase;

class NativePhpLongQueueWorkerContractTest extends TestCase
{
    public function test_long_worker_uses_database_long_default_queue_and_watchdog_restart(): void
    {
        $childProcess = Mockery::mock(ChildProcessContract::class);

        $childProcess->shouldReceive('artisan')
            ->once()
            ->withArgs(function (
                array $command,
                string $alias,
                ?array $env,
                ?bool $persistent,
                ?array $iniSettings
            ): bool {
                $this->assertSame([
                    'queue:work',
                    'database_long',
                    '--name=default-long',
                    '--queue=default',
                    '--memory=128',
                    '--timeout=300',
                    '--sleep=3',
                    '--quiet',
                ], $command);
                $this->assertSame('queue_default_long', $alias);
                $this->assertNull($env);
                $this->assertTrue($persistent);
                $this->assertSame(['memory_limit' => '128M'], $iniSettings);

                return true;
            })
            ->andReturn($childProcess);

        (new LongQueueWorkerService($childProcess))->start();
    }
}
