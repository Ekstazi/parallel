<?php

namespace Amp\Parallel\Test\Context;

use Amp\Loop;
use Amp\Parallel\Context\Process;
use Amp\PHPUnit\TestCase;

class ProcessTest extends TestCase
{
    public function testBasicProcess()
    {
        Loop::run(function () {
            $process = new Process([
                __DIR__ . "/test-process.php",
                "Test"
            ]);
            yield $process->start();
            $this->assertSame("Test", yield $process->join());
        });
    }

    /**
     * @expectedException \Amp\Parallel\Sync\PanicError
     * @expectedExceptionMessage No string provided
     */
    public function testFailingProcess()
    {
        Loop::run(function () {
            $process = new Process(__DIR__ . "/test-process.php");
            yield $process->start();
            yield $process->join();
        });
    }

    /**
     * @expectedException \Amp\Parallel\Sync\PanicError
     * @expectedExceptionMessage No script found at 'test-process.php'
     */
    public function testInvalidScriptPath()
    {
        Loop::run(function () {
            $process = new Process("test-process.php");
            yield $process->start();
            yield $process->join();
        });
    }

    /**
     * @expectedException \Amp\Parallel\Sync\PanicError
     * @expectedExceptionMessage The given data cannot be sent because it is not serializable
     */
    public function testInvalidResult()
    {
        Loop::run(function () {
            $process = new Process(__DIR__ . "/invalid-result-process.php");
            yield $process->start();
            \var_dump(yield $process->join());
        });
    }

    /**
     * @expectedException \Amp\Parallel\Sync\PanicError
     * @expectedExceptionMessage did not return a callable function
     */
    public function testNoCallbackReturned()
    {
        Loop::run(function () {
            $process = new Process(__DIR__ . "/no-callback-process.php");
            yield $process->start();
            \var_dump(yield $process->join());
        });
    }

    /**
     * @expectedException \Amp\Parallel\Sync\PanicError
     * @expectedExceptionMessage Uncaught ParseError in execution context
     */
    public function testParseError()
    {
        Loop::run(function () {
            $process = new Process(__DIR__ . "/parse-error-process.inc");
            yield $process->start();
            \var_dump(yield $process->join());
        });
    }

    public function testStartAfterJoin()
    {
        $this->assertRunTimeGreaterThan(function () {
            Loop::run(function () {
                $context = new Process(__DIR__ . "/wait-process.php");
                for ($i=0; $i<=1; $i++) {
                    $this->assertFalse($context->isRunning());

                    yield $context->start();

                    $this->assertTrue($context->isRunning());

                    yield $context->join();
                    $this->assertFalse($context->isRunning());
                }
            });
        }, 2000);
    }

    public function testStartAfterKill()
    {
        $this->assertRunTimeLessThan(function () {
            Loop::run(function () {
                $context = new Process(__DIR__ . "/wait-process.php");
                for ($i=0; $i<=1;$i++) {
                    $this->assertFalse($context->isRunning());

                    yield $context->start();

                    $this->assertTrue($context->isRunning());

                    $this->assertRunTimeLessThan([$context, 'kill'], 1000);
                    $this->assertFalse($context->isRunning());
                }
            });
        }, 2000);
    }

    public function testRestart()
    {
        $this->assertRunTimeGreaterThan(function () {
            Loop::run(function () {
                $context = new Process(__DIR__ . "/wait-process.php");
                $this->assertFalse($context->isRunning());
                yield $context->start();

                for ($i = 0; $i <= 1; $i++) {
                    $this->assertTrue($context->isRunning());

                    yield $context->restart();
                }
            });
        }, 2000);
    }

    public function testForceRestart()
    {
        $this->assertRunTimeLessThan(function () {
            Loop::run(function () {
                $context = new Process(__DIR__ . "/wait-process.php");
                $this->assertFalse($context->isRunning());
                yield $context->start();

                for ($i = 0; $i <= 1; $i++) {
                    $this->assertTrue($context->isRunning());

                    yield $context->restart(true);
                }
            });
        }, 2000);
    }
}
