<?php

namespace Amp\Parallel\Test\Worker;

use Amp\Loop;
use Amp\Parallel\Worker\Pool;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\Worker;
use Amp\PHPUnit\TestCase;
use Amp\Promise;

abstract class AbstractPoolTest extends TestCase
{
    /**
     * @param int $min
     * @param int $max
     *
     * @return \Amp\Parallel\Worker\Pool
     */
    abstract protected function createPool($max = Pool::DEFAULT_MAX_SIZE): Pool;

    public function testIsRunning()
    {
        Loop::run(function () {
            $pool = $this->createPool();

            $this->assertTrue($pool->isRunning());

            yield $pool->shutdown();
            $this->assertFalse($pool->isRunning());
        });
    }

    public function testIsIdleOnStart()
    {
        Loop::run(function () {
            $pool = $this->createPool();

            $this->assertTrue($pool->isIdle());

            yield $pool->shutdown();
        });
    }

    public function testShutdownShouldReturnSameResult()
    {
        Loop::run(function () {
            $pool = $this->createPool();

            $this->assertTrue($pool->isIdle());

            $result = yield $pool->shutdown();
            $this->assertSame($result, yield $pool->shutdown());
        });
    }

    /**
     * @expectedException         \Amp\Parallel\Context\StatusError
     * @expectedExceptionMessage  The pool was shutdown
     */
    public function testPullShouldThrowStatusError()
    {
        Loop::run(function () {
            $pool = $this->createPool();

            $this->assertTrue($pool->isIdle());

            yield $pool->shutdown();

            $pool->getWorker();
        });
    }

    public function testGetMaxSize()
    {
        $pool = $this->createPool(17);
        $this->assertEquals(17, $pool->getMaxSize());
    }

    public function testWorkersIdleOnStart()
    {
        Loop::run(function () {
            $pool = $this->createPool();

            $this->assertEquals(0, $pool->getIdleWorkerCount());

            yield $pool->shutdown();
        });
    }

    public function testEnqueue()
    {
        Loop::run(function () {
            $pool = $this->createPool();

            $returnValue = yield $pool->enqueue(new TestTask(42));
            $this->assertEquals(42, $returnValue);

            yield $pool->shutdown();
        });
    }

    public function testEnqueueMultiple()
    {
        Loop::run(function () {
            $pool = $this->createPool();

            $values = yield \Amp\Promise\all([
                $pool->enqueue(new TestTask(42)),
                $pool->enqueue(new TestTask(56)),
                $pool->enqueue(new TestTask(72))
            ]);

            $this->assertEquals([42, 56, 72], $values);

            yield $pool->shutdown();
        });
    }

    public function testKill()
    {
        $pool = $this->createPool();

        $this->assertRunTimeLessThan([$pool, 'kill'], 1000);
        $this->assertFalse($pool->isRunning());
    }

    public function testGet()
    {
        Loop::run(function () {
            $pool = $this->createPool();

            $worker = $pool->getWorker();
            $this->assertInstanceOf(Worker::class, $worker);

            $this->assertTrue($worker->isRunning());
            $this->assertTrue($worker->isIdle());

            $this->assertSame(42, yield $worker->enqueue(new TestTask(42)));

            yield $worker->shutdown();

            $worker->kill();
        });
    }

    public function testBusyPool()
    {
        Loop::run(function () {
            $pool = $this->createPool(2);

            $values = [42, 56, 72];
            $tasks = \array_map(function (int $value): Task {
                return new TestTask($value);
            }, $values);

            $promises = \array_map(function (Task $task) use ($pool): Promise {
                return $pool->enqueue($task);
            }, $tasks);

            $this->assertSame($values, yield $promises);

            $promises = \array_map(function (Task $task) use ($pool): Promise {
                return $pool->enqueue($task);
            }, $tasks);

            $this->assertSame($values, yield $promises);

            yield $pool->shutdown();
        });
    }

    /**
     * @expectedException        \Error
     * @expectedExceptionMessage Maximum size must be a non-negative integer
     */
    public function testCreatePoolShouldThrowError()
    {
        Loop::run(function () {
            $this->createPool(-1);
        });
    }

    public function testCleanGarbageCollection()
    {
        // See https://github.com/amphp/parallel-functions/issues/5
        Loop::run(function () {
            for ($i = 0; $i < 3; $i++) {
                $pool = $this->createPool(32);

                $values = \range(1, 50);
                $tasks = \array_map(function (int $value): Task {
                    return new TestTask($value);
                }, $values);

                $promises = \array_map(function (Task $task) use ($pool): Promise {
                    return $pool->enqueue($task);
                }, $tasks);

                $this->assertSame($values, yield $promises);
            }
        });
    }

	public function testRestart()
	{
		$this->assertRunTimeGreaterThan(function () {
			Loop::run(function () {
				$pool = $original = $this->createPool(1);
				for ($i = 0; $i <= 1; $i++) {
					$this->assertTrue($pool->isRunning());

					$finished = false;
					$promise = $pool->enqueue(new TestTask(42, 100));
					$promise->onResolve(function () use(&$finished){
						$finished = true;
					});

					$pool = yield $pool->restart();

					$this->assertFalse($original->isRunning());
					$this->assertTrue($finished);
					$this->assertNotEquals($pool, $original);
				}
			});
		}, 200);
	}

	public function testForceRestart()
	{
		$this->assertRunTimeLessThan(function () {
			Loop::run(function () {
				$pool = $original = $this->createPool(1);
				for ($i = 0; $i <= 1; $i++) {
					$this->assertTrue($pool->isRunning());

					$pool->enqueue(new TestTask(42, 1000));
					$pool = yield $pool->restart(true);

					$this->assertFalse($original->isRunning());
					$this->assertNotEquals($pool, $original);
				}
				$this->assertEquals($i, 2);
			});
		}, 2000);
	}

	public function testWorkerRestart()
	{
		$this->assertRunTimeGreaterThan(function () {
			Loop::run(function () {
				$pool = $original = $this->createPool(1);
				for ($i = 0; $i <= 1; $i++) {
					$this->assertTrue($pool->isRunning());

					$finished = false;
					$promise = $pool->enqueue(new TestTask(42, 100));
					$promise->onResolve(function () use(&$finished){
						$finished = true;
					});

					$pool = yield $pool->restart();

					$this->assertFalse($original->isRunning());
					$this->assertTrue($finished);
					$this->assertNotEquals($pool, $original);
				}
			});
		}, 200);
	}

	public function testWorkerForceRestart()
	{
		$this->assertRunTimeLessThan(function () {
			Loop::run(function () {
				$pool  = $this->createPool(1);
				$worker = $original = $pool->getWorker();
				for ($i = 0; $i <= 1; $i++) {
					$this->assertTrue($pool->isRunning());

					$worker->enqueue(new TestTask(42, 100));
					$worker = yield $worker->restart(true);

					$this->assertFalse($original->isRunning());
					$this->assertNotEquals($worker, $original);
				}
				$this->assertEquals($i, 2);
			});
		}, 200);
	}

	public function testRestartedWorkerPushed()
	{
		Loop::run(function () {
			$pool  = $this->createPool(1);
			$worker = $original = $pool->getWorker();

			/** @var Worker $restarted */
			$restarted = yield $worker->restart();
			$reference = clone $restarted;
			// force destruct
			$worker->__destruct();
			unset($worker);
			$restarted->__destruct();
			unset($restarted);

			$pulled = $pool->getWorker();
			$this->assertFalse($original->isRunning());
			$reference->kill();
			$this->assertFalse($pulled->isRunning());
		});
	}
}
