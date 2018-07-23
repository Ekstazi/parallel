<?php

namespace Amp\Parallel\Worker\Internal;

use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\Worker;

/** @internal */
class PooledWorker implements Worker {
    /** @var callable */
    private $push;

    /** @var Worker */
    private $worker;

    /**
     * @param Worker   $worker
     * @param callable $push Callable to push the worker back into the queue.
     */
    public function __construct(Worker $worker, callable $push) {
        $this->worker = $worker;
        $this->push = $push;
    }

    /**
     * Automatically pushes the worker back into the queue.
     */
    public function __destruct() {
        ($this->push)($this->worker);
    }

    /**
     * {@inheritdoc}
     */
    public function isRunning(): bool {
        return $this->worker->isRunning();
    }

    /**
     * {@inheritdoc}
     */
    public function isIdle(): bool {
        return $this->worker->isIdle();
    }

    /**
     * {@inheritdoc}
     */
    public function enqueue(Task $task) {
        return $this->worker->enqueue($task);
    }

    /**
     * {@inheritdoc}
     */
    public function shutdown(): int {
        return $this->worker->shutdown();
    }

    /**
     * {@inheritdoc}
     */
    public function kill(): void {
        $this->worker->kill();
    }
}
