<?php

namespace Tests\Feature;

use App\Console\Commands\WorkMediaQueue;
use App\Models\MediaWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MediaWorkerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $workerLock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workerLock = storage_path('framework/testing-worker-'.Str::uuid().'.lock');
        config(['operations.worker_lock' => $this->workerLock]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->workerLock)) {
            unlink($this->workerLock);
        }
        parent::tearDown();
    }

    private function command(callable $factory): WorkMediaQueue
    {
        $command = new class($factory) extends WorkMediaQueue
        {
            public function __construct(private $factory)
            {
                parent::__construct();
            }

            protected function makeWorkerProcess(MediaWorker $worker): Process
            {
                return ($this->factory)($worker);
            }
        };
        $command->setLaravel($this->app);

        return $command;
    }

    private function exitedChild(int $code): Process
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('start')->once();
        $process->shouldReceive('isRunning')->andReturn(false);
        $process->shouldReceive('getExitCode')->once()->andReturn($code);

        return $process;
    }

    public function test_supervisor_survives_idle_recycling_and_an_unexpected_child_exit(): void
    {
        $idle = $this->exitedChild(0);
        $crashed = $this->exitedChild(1);
        $cycles = 0;
        $command = $this->command(function (MediaWorker $worker) use (&$cycles, $idle, $crashed): Process {
            $cycles++;
            $this->assertNull($worker->fresh()->stopped_at);
            $this->assertNotNull($worker->fresh()->heartbeat_at);
            if ($cycles === 1) {
                return $idle;
            }
            if ($cycles === 2) {
                return $crashed;
            }
            $this->assertSame('queue_worker_restarting', $worker->fresh()->error_code);
            throw new RuntimeException('End the controlled supervisor test.');
        });
        try {
            $command->run(new ArrayInput([], $command->getDefinition()), new BufferedOutput);
            $this->fail('The controlled test did not end.');
        } catch (RuntimeException $exception) {
            $this->assertSame('End the controlled supervisor test.', $exception->getMessage());
        }
        $this->assertSame(3, $cycles);
        $this->assertSame(1, MediaWorker::count());
        $this->assertNotNull(MediaWorker::first()->stopped_at);
    }

    public function test_once_mode_exits_cleanly_after_one_child_and_records_its_status(): void
    {
        $command = $this->command(fn (): Process => $this->exitedChild(0));
        $result = $command->run(new ArrayInput(['--once' => true], $command->getDefinition()), new BufferedOutput);
        $this->assertSame(0, $result);
        $this->assertNull(MediaWorker::first()->error_code);
        $this->assertNotNull(MediaWorker::first()->stopped_at);
    }

    public function test_timeout_stops_the_child_and_releases_worker_capacity(): void
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('start')->once();
        $process->shouldReceive('isRunning')->andReturn(true, false);
        $process->shouldReceive('getTimeout')->andReturn(1830.0);
        $process->shouldReceive('getCommandLine')->andReturn('php artisan queue:work');
        $process->shouldReceive('checkTimeout')->once()->andThrow(new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL));
        $process->shouldReceive('stop')->once()->with(5);
        $command = $this->command(fn (): Process => $process);
        $result = $command->run(new ArrayInput(['--once' => true], $command->getDefinition()), new BufferedOutput);
        $this->assertSame(1, $result);
        $worker = MediaWorker::first();
        $this->assertNull($worker->current_task_id);
        $this->assertSame('queue_worker_stopped', $worker->error_code);
        $this->assertNotNull($worker->stopped_at);
    }

    public function test_automatic_worker_stops_after_idle_and_records_a_clean_shutdown(): void
    {
        $calls = 0;
        $command = $this->command(function () use (&$calls): Process {
            $calls++;
            $process = Mockery::mock(Process::class);
            $process->shouldReceive('start')->once()->andReturnUsing(function (): void {
                $this->travel(31)->seconds();
            });
            $process->shouldReceive('isRunning')->andReturn(false);
            $process->shouldReceive('getExitCode')->once()->andReturn(0);

            return $process;
        });
        $this->freezeTime();
        $result = $command->run(new ArrayInput(['--auto' => true], $command->getDefinition()), new BufferedOutput);
        $this->assertSame(0, $result);
        $this->assertSame(1, $calls);
        $this->assertTrue(MediaWorker::first()->automatic);
        $this->assertNotNull(MediaWorker::first()->stopped_at);
        $this->assertNull(MediaWorker::first()->error_code);
    }

    public function test_a_second_command_cannot_create_a_duplicate_worker(): void
    {
        $lock = fopen(config('operations.worker_lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $command = $this->command(function (): Process {
                throw new RuntimeException('Duplicate worker launched.');
            });
            $result = $command->run(new ArrayInput([], $command->getDefinition()), new BufferedOutput);
            $this->assertSame(0, $result);
            $this->assertSame(0, MediaWorker::count());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_delayed_work_keeps_the_automatic_supervisor_alive_until_the_queue_is_empty(): void
    {
        $this->freezeTime();
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'media', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->addMinutes(5)->timestamp, 'created_at' => now()->timestamp]);
        $calls = 0;
        $command = $this->command(function () use (&$calls): Process {
            $calls++;
            $this->assertLessThanOrEqual(2, $calls);
            $process = Mockery::mock(Process::class);
            $process->shouldReceive('start')->once()->andReturnUsing(function () use (&$calls): void {
                if ($calls === 2) {
                    DB::table('jobs')->delete();
                }
                $this->travel(31)->seconds();
            });
            $process->shouldReceive('isRunning')->andReturn(false);
            $process->shouldReceive('getExitCode')->once()->andReturn(0);

            return $process;
        });
        $result = $command->run(new ArrayInput(['--auto' => true], $command->getDefinition()), new BufferedOutput);
        $this->assertSame(0, $result);
        $this->assertSame(2, $calls);
        $this->assertNotNull(MediaWorker::first()->stopped_at);
    }
}
