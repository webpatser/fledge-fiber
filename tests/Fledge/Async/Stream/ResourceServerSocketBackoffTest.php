<?php

declare(strict_types=1);

use Fledge\Async\Stream\BindContext;
use Fledge\Async\Stream\Internal\AcceptFailure;
use Fledge\Async\Stream\ResourceServerSocket;
use Fledge\Async\TimeoutCancellation;
use Fledge\Loop;
use Psr\Log\AbstractLogger;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/*
 * At the open file limit accept() fails with EMFILE while the connection stays in the backlog, so the listener stays
 * readable. ResourceServerSocket must back off on a timer instead of spinning on the readable callback, report the
 * condition once, and resume by itself. The limit is lowered in a subprocess (Fixtures/accept-backoff.php) so the
 * test runner keeps its own descriptors.
 */

/**
 * @return array<string, mixed>
 */
function runAcceptBackoffFixture(string $driver, string $mode): array
{
    $process = \proc_open(
        [
            \PHP_BINARY, '-d', 'zend.assertions=1', '-d', 'assert.exception=1', '-d', 'display_errors=stderr',
            __DIR__ . '/Fixtures/accept-backoff.php', \dirname(__DIR__, 4) . '/vendor/autoload.php', $mode,
        ],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        [...\getenv(), 'FLEDGE_EVENT_LOOP' => $driver],
    );

    $stdout = \stream_get_contents($pipes[1]);
    $stderr = \stream_get_contents($pipes[2]);
    $exitCode = \proc_close($process);

    expect($exitCode)->toBe(0, "fixture failed:\n" . $stdout . $stderr);

    $result = \json_decode(\trim($stdout), true);
    expect($result)->toBeArray("fixture printed no report:\n" . $stdout . $stderr);

    if (isset($result['skip'])) {
        test()->markTestSkipped($result['skip']);
    }

    return $result;
}

describe('ResourceServerSocket at the open file limit', function () {
    it('backs off without spinning, warns once and resumes when descriptors are freed', function (string $driver) {
        $result = runAcceptBackoffFixture($driver, 'resume');

        expect($result['accepted_at_limit'])->toBe(0)
            ->and($result['cpu_at_limit'])->toBeLessThan(0.05)
            ->and($result['warnings_at_limit'])->toHaveCount(1)
            ->and($result['warnings_at_limit'][0])->toContain('Too many open files')
            ->and($result['resumed_after'])->not->toBeNull()
            ->and($result['resumed_after'])->toBeLessThan(AcceptFailure::BACKOFF_MAX + 0.5)
            ->and($result['warnings'])->toHaveCount(2)
            ->and($result['warnings'][1])->toContain('resumed');
    })->with(['poll', 'select']);

    it('cancels the back-off timer when the listener is closed during back-off', function (string $driver) {
        $result = runAcceptBackoffFixture($driver, 'close');

        expect($result['warnings_before_close'])->toBe(1)
            ->and($result['accepted'])->toBe(0)
            ->and($result['timers_left'])->toBe(0);
    })->with(['poll', 'select']);
});

describe('ResourceServerSocket transient accept failures', function () {
    it('retries a failed accept on spurious readiness without backing off', function () {
        $listener = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $address = 'tcp://' . \stream_socket_get_name($listener, false);

        // Registered before the server's own readable callback, so it runs first in the tick and takes the
        // connection: the server is woken for a connection that is gone and its accept fails.
        $stolen = [];
        $stealer = Loop::onReadable($listener, static function () use ($listener, &$stolen): void {
            if ($socket = @\stream_socket_accept($listener, 0)) {
                $stolen[] = $socket;
            }
        });

        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };

        $server = new ResourceServerSocket($listener, new BindContext(), logger: $logger);

        try {
            $accept = async(static fn () => $server->accept(new TimeoutCancellation(2)));
            delay(0.01);

            $first = \stream_socket_client($address);
            delay(0.05);

            expect($stolen)->toHaveCount(1)
                ->and($accept->isComplete())->toBeFalse();

            Loop::cancel($stealer);

            $second = \stream_socket_client($address);
            $socket = $accept->await();

            expect($socket)->not->toBeNull()
                ->and($logger->records)->toBe([]);

            $socket->close();
            \fclose($first);
            \fclose($second);
        } finally {
            Loop::cancel($stealer);
            $server->close();
        }
    });

    it('classifies strerror texts', function (?string $message, bool $transient) {
        expect(AcceptFailure::isTransient($message))->toBe($transient);
    })->with([
        'EMFILE' => ['stream_socket_accept(): Accept failed: Too many open files', false],
        'ENFILE' => ['stream_socket_accept(): Accept failed: Too many open files in system', false],
        'ENOBUFS' => ['stream_socket_accept(): Accept failed: No buffer space available', false],
        'ENOMEM glibc/macOS' => ['stream_socket_accept(): Accept failed: Cannot allocate memory', false],
        'ENOMEM musl' => ['stream_socket_accept(): Accept failed: Out of memory', false],
        'unknown' => ['stream_socket_accept(): Accept failed: Unknown error', false],
        'no message' => [null, false],
        'ETIMEDOUT Linux' => ['stream_socket_accept(): Accept failed: Connection timed out', true],
        'ETIMEDOUT macOS' => ['stream_socket_accept(): Accept failed: Operation timed out', true],
        'EAGAIN' => ['stream_socket_accept(): Accept failed: Resource temporarily unavailable', true],
        'EINTR' => ['stream_socket_accept(): Accept failed: Interrupted system call', true],
        'ECONNABORTED' => ['stream_socket_accept(): Accept failed: Software caused connection abort', true],
        'EPROTO' => ['stream_socket_accept(): Accept failed: Protocol error', true],
        'ECONNRESET' => ['stream_socket_accept(): Accept failed: Connection reset by peer', true],
    ]);
});
