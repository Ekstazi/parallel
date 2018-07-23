<?php

namespace Amp\Parallel\Worker;

use Amp\Parallel\Context\StatusError;

/**
 * An interface for worker pools.
 */
interface Pool extends Worker {
    /** @var int The default maximum pool size. */
    public const DEFAULT_MAX_SIZE = 32;

    /**
     * Gets a worker from the pool. The worker is marked as busy and will only be reused if the pool runs out of
     * idle workers. The worker will be automatically marked as idle once no references to the returned worker remain.
     *
     * @return Worker
     *
     * @throws StatusError If the queue is not running.
     */
    public function get(): Worker;

    /**
     * Gets the number of workers currently running in the pool.
     *
     * @return int The number of workers.
     */
    public function getWorkerCount(): int;

    /**
     * Gets the number of workers that are currently idle.
     *
     * @return int The number of idle workers.
     */
    public function getIdleWorkerCount(): int;

    /**
     * Gets the maximum number of workers the pool may spawn to handle concurrent tasks.
     *
     * @return int The maximum number of workers.
     */
    public function getMaxSize(): int;
}
