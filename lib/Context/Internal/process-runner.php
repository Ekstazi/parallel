<?php

namespace Amp\Parallel\Context\Internal;

use Amp\Loop;
use Amp\Parallel\Context\Process;
use Amp\Parallel\Sync;
use function Amp\call;

// Doesn't exist in phpdbg...
if (\function_exists("cli_set_process_title")) {
    @\cli_set_process_title("amp-process");
}

// Redirect all output written using echo, print, printf, etc. to STDERR.
\ob_start(function ($data) {
    \fwrite(\STDERR, $data);
    return '';
}, 1, \PHP_OUTPUT_HANDLER_CLEANABLE | \PHP_OUTPUT_HANDLER_FLUSHABLE);

(function () {
    $paths = [
        \dirname(__DIR__, 5) . "/autoload.php",
        \dirname(__DIR__, 3) . "/vendor/autoload.php",
    ];

    foreach ($paths as $path) {
        if (\file_exists($path)) {
            $autoloadPath = $path;
            break;
        }
    }

    if (!isset($autoloadPath)) {
        \fwrite(\STDERR, "Could not locate autoload.php in any of the following files: " . \implode(", ", $paths) . \PHP_EOL);
        exit(1);
    }

    require $autoloadPath;
})();

Loop::run(function () use ($argc, $argv) {
    // Remove this scripts path from process arguments.
    --$argc;
    \array_shift($argv);

    if (!isset($argv[0])) {
        throw new \Error("No socket path provided");
    }

    // Remove socket path from process arguments.
    --$argc;
    $uri = \array_shift($argv);

    // Read random key from STDIN and send back to parent over IPC socket to authenticate.
    $key = \fread(\STDIN, Process::KEY_LENGTH);

    if (!$socket = \stream_socket_client($uri, $errno, $errstr, 5, \STREAM_CLIENT_CONNECT)) {
        exit(1); // Parent context died, simply exit.
    }

    $channel = new Sync\ChannelledSocket($socket, $socket);

    try {
        yield $channel->send($key);
    } catch (\Throwable $exception) {
        exit(1); // Parent context died, simply exit.
    }

    try {
        // Protect current scope by requiring script within another function.
        $callable = (function () use ($argc, $argv): callable {
            if (!isset($argv[0])) {
                throw new \Error("No script path given");
            }

            if (!\is_file($argv[0])) {
                throw new \Error(\sprintf("No script found at '%s' (be sure to provide the full path to the script)", $argv[0]));
            }

            $callable = require $argv[0];

            if (!\is_callable($callable)) {
                throw new \Error(\sprintf("Script '%s' did not return a callable function", $argv[0]));
            }

            return $callable;
        })();

        $result = new Sync\ExitSuccess(yield call($callable, $channel));
    } catch (Sync\ChannelException $exception) {
        exit(1); // Parent context died, simply exit.
    } catch (\Throwable $exception) {
        $result = new Sync\ExitFailure($exception);
    }

    try {
        try {
            yield $channel->send($result);
        } catch (Sync\SerializationException $exception) {
            // Serializing the result failed. Send the reason why.
            yield $channel->send(new Sync\ExitFailure($exception));
        }
    } catch (\Throwable $exception) {
        exit(1); // Parent context died, simply exit.
    }
});