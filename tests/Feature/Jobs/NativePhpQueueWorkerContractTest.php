<?php

namespace Tests\Feature\Jobs;

use Mockery;
use Native\Desktop\Contracts\ChildProcess as ChildProcessContract;
use Native\Desktop\QueueWorker;
use Tests\TestCase;

class NativePhpQueueWorkerContractTest extends TestCase
{
    public function test_duckietv_nativephp_worker_contract_prioritizes_autodownload_and_is_persistent(): void
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
                    '--name=default',
                    '--queue=autodownload,default',
                    '--memory=128',
                    '--timeout=300',
                    '--sleep=3',
                    '--quiet',
                ], $command);
                $this->assertSame('queue_default', $alias);
                $this->assertNull($env);
                $this->assertTrue($persistent);
                $this->assertSame(['memory_limit' => '128M'], $iniSettings);

                return true;
            })
            ->andReturn($childProcess);

        $worker = new QueueWorker($childProcess);
        $worker->up('default');
    }
}
