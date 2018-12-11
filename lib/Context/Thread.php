<?php

namespace Amp\Parallel\Context;

use Amp\Failure;
use Amp\Loop;
use Amp\Parallel\Sync\ChannelException;
use Amp\Parallel\Sync\ChannelledSocket;
use Amp\Parallel\Sync\ExitResult;
use Amp\Parallel\Sync\SynchronizationError;
use Amp\Promise;
use Amp\Success;
use function Amp\call;

/**
 * Implements an execution context using native multi-threading.
 *
 * The thread context is not itself threaded. A local instance of the context is
 * maintained both in the context that creates the thread and in the thread
 * itself.
 */
final class Thread implements Context
{
    const EXIT_CHECK_FREQUENCY = 250;

    /** @var Internal\Thread An internal thread instance. */
    private $thread;

    /** @var \Amp\Parallel\Sync\ChannelledSocket A channel for communicating with the thread. */
    private $channel;

    /** @var resource */
    private $socket;

    /** @var callable */
    private $function;

    /** @var mixed[] */
    private $args;

    /** @var int */
    private $oid = 0;

    /** @var string */
    private $watcher;

    /**
     * Checks if threading is enabled.
     *
     * @return bool True if threading is enabled, otherwise false.
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('pthreads');
    }

    /**
     * Creates and starts a new thread.
     *
     * @param callable $function The callable to invoke in the thread. First argument is an instance of
     *     \Amp\Parallel\Sync\Channel.
     * @param mixed ...$args Additional arguments to pass to the given callable.
     *
     * @return Promise<Thread> The thread object that was spawned.
     */
    public static function run(callable $function, ...$args): Promise
    {
        $thread = new self($function, ...$args);
        return call(function () use ($thread) {
            yield $thread->start();
            return $thread;
        });
    }

    /**
     * Creates a new thread.
     *
     * @param callable $function The callable to invoke in the thread. First argument is an instance of
     *     \Amp\Parallel\Sync\Channel.
     * @param mixed ...$args Additional arguments to pass to the given callable.
     *
     * @throws \Error Thrown if the pthreads extension is not available.
     */
    public function __construct(callable $function, ...$args)
    {
        if (!self::isSupported()) {
            throw new \Error("The pthreads extension is required to create threads.");
        }

        $this->function = $function;
        $this->args = $args;
    }

    /**
     * Returns the thread to the condition before starting. The new thread can be started and run independently of the
     * first thread.
     */
    public function __clone()
    {
        $this->thread = null;
        $this->socket = null;
        $this->channel = null;
        $this->oid = 0;
    }

    /**
     * Kills the thread if it is still running.
     *
     * @throws \Amp\Parallel\Context\ContextException
     */
    public function __destruct()
    {
        if (\getmypid() === $this->oid) {
            $this->kill();
        }
    }

    /**
     * Checks if the context is running.
     *
     * @return bool True if the context is running, otherwise false.
     */
    public function isRunning(): bool
    {
        return $this->channel !== null;
    }

    /**
     * Spawns the thread and begins the thread's execution.
     *
     * @return Promise<null> Resolved once the thread has started.
     *
     * @throws \Amp\Parallel\Context\StatusError If the thread has already been started.
     * @throws \Amp\Parallel\Context\ContextException If starting the thread was unsuccessful.
     */
    public function start(): Promise
    {
        if ($this->oid !== 0) {
            throw new StatusError('The thread has already been started.');
        }

        $this->oid = \getmypid();

        $sockets = @\stream_socket_pair(
            \stripos(\PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );

        if ($sockets === false) {
            $message = "Failed to create socket pair";
            if ($error = \error_get_last()) {
                $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
            }
            return new Failure(new ContextException($message));
        }

        list($channel, $this->socket) = $sockets;

        $thread = $this->thread = new Internal\Thread($this->socket, $this->function, $this->args);

        if (!$this->thread->start(\PTHREADS_INHERIT_INI)) {
            return new Failure(new ContextException('Failed to start the thread.'));
        }

        $channel = $this->channel = new ChannelledSocket($channel, $channel);

        $this->watcher = Loop::repeat(self::EXIT_CHECK_FREQUENCY, static function ($watcher) use ($thread, $channel) {
            if (!$thread->isRunning()) {
                // Delay closing to avoid race condition between thread exiting and data becoming available.
                Loop::delay(self::EXIT_CHECK_FREQUENCY, [$channel, "close"]);
                Loop::cancel($watcher);
            }
        });

        Loop::disable($this->watcher);

        return new Success;
    }

    /**
     * Immediately kills the context.
     *
     * @throws ContextException If killing the thread was unsuccessful.
     */
    public function kill()
    {
        if ($this->thread !== null) {
            try {
                if ($this->thread->isRunning() && !$this->thread->kill()) {
                    throw new ContextException('Could not kill thread.');
                }
            } finally {
                $this->close();
            }
        }
    }

    /**
     * Closes channel and socket if still open.
     */
    private function close()
    {
        if ($this->channel !== null) {
            $this->channel->close();
        }

        $this->channel = null;
        Loop::cancel($this->watcher);
    }

    /**
     * Gets a promise that resolves when the context ends and joins with the
     * parent context.
     *
     * @return \Amp\Promise<mixed>
     *
     * @throws StatusError Thrown if the context has not been started.
     * @throws SynchronizationError Thrown if an exit status object is not received.
     * @throws ContextException If the context stops responding.
     */
    public function join(): Promise
    {
        if ($this->channel == null || $this->thread === null) {
            throw new StatusError('The thread has not been started or has already finished.');
        }

        return call(function () {
            Loop::enable($this->watcher);

            try {
                $response = yield $this->channel->receive();

                if (!$response instanceof ExitResult) {
                    throw new SynchronizationError('Did not receive an exit result from thread.');
                }
            } catch (ChannelException $exception) {
                $this->kill();
                throw new ContextException(
                    "The context stopped responding, potentially due to a fatal error or calling exit",
                    0,
                    $exception
                );
            } catch (\Throwable $exception) {
                $this->kill();
                throw $exception;
            } finally {
                Loop::disable($this->watcher);
                $this->close();
            }

            return $response->getResult();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function receive(): Promise
    {
        if ($this->channel === null) {
            throw new StatusError('The process has not been started.');
        }

        return call(function () {
            Loop::enable($this->watcher);

            try {
                $data = yield $this->channel->receive();
            } finally {
                Loop::disable($this->watcher);
            }

            if ($data instanceof ExitResult) {
                $data = $data->getResult();
                throw new SynchronizationError(\sprintf(
                    'Thread process unexpectedly exited with result of type: %s',
                    \is_object($data) ? \get_class($data) : \gettype($data)
                ));
            }

            return $data;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function send($data): Promise
    {
        if ($this->channel === null) {
            throw new StatusError('The thread has not been started or has already finished.');
        }

        if ($data instanceof ExitResult) {
            throw new \Error('Cannot send exit result objects.');
        }

        return call(function () use ($data) {
            Loop::enable($this->watcher);

            try {
                $result = yield $this->channel->send($data);
            } finally {
                Loop::disable($this->watcher);
            }

            return $result;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function restart($force = false): Promise
    {
        return call(function () use ($force) {
            if ($force) {
                $this->kill();
            } else {
                yield $this->join();
            }
            $instance = new static($this->function, ...$this->args);
            yield $instance->start();
            return $instance;
        });
    }
}
