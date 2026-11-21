<?php

declare(strict_types=1);

namespace Tests\Fledge\Async\EventLoop;

use Revolt\EventLoop\Driver;

/**
 * Targeted tests for PollDriver behaviour that Revolt's generic driver suite does not cover. Mixed into the
 * abstract per-backend test case, so every available backend runs all of them. Each test is bounded by a watchdog
 * timer so a driver bug fails the test instead of hanging the suite.
 *
 * @property Driver $loop
 */
trait PollDriverTargetedTests
{
    /**
     * Run the loop after $setup registered its callbacks; fail if it does not finish within $limit seconds.
     * The watchdog is unreferenced, so it never keeps an otherwise empty loop alive.
     *
     * @param callable(Driver): void $setup
     */
    private function runBounded(callable $setup, float $limit = 5.0): void
    {
        $timedOut = false;
        $loop = $this->loop;

        $watchdog = $loop->delay($limit, static function () use (&$timedOut, $loop): void {
            $timedOut = true;
            $loop->stop();
        });
        $loop->unreference($watchdog);

        $setup($loop);
        $loop->run();
        $loop->cancel($watchdog);

        self::assertFalse($timedOut, 'Watchdog fired: the loop did not finish within ' . $limit . ' seconds');
    }

    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair, 'stream_socket_pair() failed');

        \stream_set_blocking($pair[0], false);
        \stream_set_blocking($pair[1], false);

        return $pair;
    }

    public function testReadAndWriteCallbacksOnSameStreamBothFire(): void
    {
        [$a, $b] = $this->socketPair();
        \fwrite($b, 'ping');

        $readFired = 0;
        $writeFired = 0;
        $received = '';

        $this->runBounded(function (Driver $loop) use ($a, &$readFired, &$writeFired, &$received): void {
            $loop->onReadable($a, function (string $id, $stream) use ($loop, &$readFired, &$received): void {
                $readFired++;
                $received .= \fread($stream, 8192);
                $loop->cancel($id);
            });

            $loop->onWritable($a, function (string $id) use ($loop, &$writeFired): void {
                $writeFired++;
                $loop->cancel($id);
            });
        });

        self::assertSame(1, $readFired);
        self::assertSame(1, $writeFired);
        self::assertSame('ping', $received);
    }

    public function testCancellingOneOfTwoReadableCallbacksKeepsTheOtherActive(): void
    {
        [$a, $b] = $this->socketPair();

        $first = 0;
        $second = 0;

        $this->runBounded(function (Driver $loop) use ($a, $b, &$first, &$second): void {
            $firstId = $loop->onReadable($a, function () use (&$first): void {
                $first++;
            });

            $loop->onReadable($a, function (string $id, $stream) use ($loop, &$second): void {
                $second++;
                \fread($stream, 8192);
                $loop->cancel($id);
            });

            $loop->cancel($firstId);
            \fwrite($b, 'x');
        });

        self::assertSame(0, $first, 'The cancelled callback must not fire');
        self::assertSame(1, $second, 'The remaining callback must still fire');
    }

    public function testCancellingAllReadableCallbacksStopsWatchingTheStream(): void
    {
        [$a, $b] = $this->socketPair();

        $fired = 0;
        $later = false;

        $this->runBounded(function (Driver $loop) use ($a, $b, &$fired, &$later): void {
            $ids = [
                $loop->onReadable($a, function () use (&$fired): void {
                    $fired++;
                }),
                $loop->onReadable($a, function () use (&$fired): void {
                    $fired++;
                }),
            ];

            foreach ($ids as $id) {
                $loop->cancel($id);
            }

            \fwrite($b, 'x');

            // Data stays unread, so a stale registration would fire repeatedly while this timer is pending.
            $loop->delay(0.1, function () use (&$later): void {
                $later = true;
            });
        });

        self::assertTrue($later);
        self::assertSame(0, $fired);

        // The stream can be registered again after every callback was cancelled.
        $again = 0;
        $this->runBounded(function (Driver $loop) use ($a, &$again): void {
            $loop->onReadable($a, function (string $id, $stream) use ($loop, &$again): void {
                $again++;
                \fread($stream, 8192);
                $loop->cancel($id);
            });
        });

        self::assertSame(1, $again);
    }

    public function testDisableThenEnableCallback(): void
    {
        [$a, $b] = $this->socketPair();
        \fwrite($b, 'x');

        $fired = 0;
        $firedWhileDisabled = null;

        $this->runBounded(function (Driver $loop) use ($a, &$fired, &$firedWhileDisabled): void {
            $id = $loop->onReadable($a, function (string $id, $stream) use ($loop, &$fired): void {
                $fired++;
                \fread($stream, 8192);
                $loop->cancel($id);
            });

            $loop->disable($id);

            $loop->delay(0.1, function () use ($loop, $id, &$fired, &$firedWhileDisabled): void {
                $firedWhileDisabled = $fired;
                $loop->enable($id);
            });
        });

        self::assertSame(0, $firedWhileDisabled, 'A disabled callback must not fire');
        self::assertSame(1, $fired, 'A re-enabled callback must fire');
    }

    public function testDisablingOneEventKeepsTheOtherEventOnTheSameStream(): void
    {
        [$a, $b] = $this->socketPair();
        \fwrite($b, 'x');

        $readFired = 0;
        $writeFired = 0;

        $this->runBounded(function (Driver $loop) use ($a, &$readFired, &$writeFired): void {
            $readId = $loop->onReadable($a, function () use (&$readFired): void {
                $readFired++;
            });

            $loop->onWritable($a, function (string $id) use ($loop, $readId, &$writeFired): void {
                $writeFired++;
                $loop->cancel($id);
                $loop->cancel($readId);
            });

            $loop->disable($readId);
        });

        self::assertSame(0, $readFired);
        self::assertSame(1, $writeFired);
    }

    public function testStreamClosedWhileCallbackRegisteredDoesNotThrowOutOfTheLoop(): void
    {
        [$a, $b] = $this->socketPair();
        [$c, $d] = $this->socketPair();

        $otherFired = false;

        try {
            $this->runBounded(function (Driver $loop) use ($a, $c, $d, &$otherFired): void {
                $closedId = $loop->onReadable($a, static function (): void {
                });

                $loop->onReadable($c, function (string $id, $stream) use ($loop, $closedId, &$otherFired): void {
                    $otherFired = true;
                    \fread($stream, 8192);
                    $loop->cancel($id);
                    $loop->cancel($closedId);
                });

                $loop->defer(static function () use ($a, $d): void {
                    \fclose($a);
                    \fwrite($d, 'x');
                });
            });
        } catch (\Throwable $e) {
            self::fail('Closing a watched stream must not throw out of the loop: ' . $e::class . ': ' . $e->getMessage());
        }

        self::assertTrue($otherFired, 'Other streams must keep working after a watched stream was closed');
    }

    public function testFileDescriptorsAboveSelectLimitAreWatched(): void
    {
        if (!\function_exists('posix_getrlimit') || !\function_exists('posix_setrlimit')) {
            self::markTestSkipped('ext-posix is required to inspect and raise the open files limit');
        }

        $pairCount = 530; // 1060 descriptors, so the last pair is certainly numbered above 1024
        $needed = $pairCount * 2 + 128;

        $limits = \posix_getrlimit();
        $soft = $limits['soft openfiles'] ?? 'unlimited';
        $hard = $limits['hard openfiles'] ?? 'unlimited';
        $restore = null;

        if ($soft !== 'unlimited' && $soft < $needed) {
            if ($hard !== 'unlimited' && $hard < $needed) {
                self::markTestSkipped("Hard open files limit is $hard, need $needed to open descriptors above 1024");
            }

            $hardValue = $hard === 'unlimited' ? \POSIX_RLIMIT_INFINITY : $hard;
            if (!@\posix_setrlimit(\POSIX_RLIMIT_NOFILE, $needed, $hardValue)) {
                self::markTestSkipped("Soft open files limit is $soft and could not be raised to $needed");
            }

            $restore = [$soft, $hardValue];
        }

        $pairs = [];

        try {
            for ($i = 0; $i < $pairCount; $i++) {
                $pair = @\stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
                if ($pair === false) {
                    self::markTestSkipped("Could not open $pairCount socket pairs (failed at pair $i)");
                }

                \stream_set_blocking($pair[0], false);
                \stream_set_blocking($pair[1], false);
                $pairs[] = $pair;
            }

            [$low, $lowPeer] = $pairs[0];
            [$high, $highPeer] = $pairs[$pairCount - 1];
            \fwrite($lowPeer, 'low');
            \fwrite($highPeer, 'high');

            $received = [];
            $writable = false;

            $this->runBounded(function (Driver $loop) use ($low, $high, $highPeer, &$received, &$writable): void {
                $loop->onReadable($low, function (string $id, $stream) use ($loop, &$received): void {
                    $received['low'] = \fread($stream, 8192);
                    $loop->cancel($id);
                });

                $loop->onReadable($high, function (string $id, $stream) use ($loop, &$received): void {
                    $received['high'] = \fread($stream, 8192);
                    $loop->cancel($id);
                });

                $loop->onWritable($highPeer, function (string $id) use ($loop, &$writable): void {
                    $writable = true;
                    $loop->cancel($id);
                });
            }, 10.0);

            self::assertSame(['low' => 'low', 'high' => 'high'], $received);
            self::assertTrue($writable);
        } finally {
            foreach ($pairs as [$x, $y]) {
                \fclose($x);
                \fclose($y);
            }

            if ($restore !== null) {
                @\posix_setrlimit(\POSIX_RLIMIT_NOFILE, $restore[0], $restore[1]);
            }
        }
    }

    public function testUnselectableStreamFailsLikeStreamSelectDriver(): void
    {
        // php://memory can be watched by neither Io\Poll nor stream_select(). Like StreamSelectDriver, registering
        // succeeds and the next dispatch throws the stream_select() warning as an exception out of run().
        $memory = \fopen('php://memory', 'w+');

        [$a, $b] = $this->socketPair();
        \fwrite($b, 'x');

        $fired = false;
        $thrown = null;

        try {
            $this->runBounded(function (Driver $loop) use ($memory, $a, &$fired): void {
                $loop->onReadable($memory, function (string $id) use ($loop, &$fired): void {
                    $fired = true;
                    $loop->cancel($id);
                });

                $loop->onReadable($a, function (string $id, $stream) use ($loop): void {
                    \fread($stream, 8192);
                    $loop->cancel($id);
                });
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertFalse($fired, 'A php://memory callback must never fire');
        self::assertInstanceOf(\Exception::class, $thrown);
        self::assertSame(\Exception::class, $thrown::class);
        self::assertSame(
            'stream_select(): Cannot represent a stream of type MEMORY as a select()able descriptor',
            $thrown->getMessage()
        );
    }

    public function testStreamRejectedByBackendUsesFallbackPath(): void
    {
        // /dev/null is rejected by kqueue (and epoll) and never reported by macOS poll(2), but stream_select()
        // handles it, so its callback fires through the driver's fallback next to a backend-watched socket.
        $null = \fopen('/dev/null', 'r');

        [$a, $b] = $this->socketPair();
        \fwrite($b, 'x');

        $fired = ['null' => 0, 'socket' => 0];

        $this->runBounded(function (Driver $loop) use ($null, $a, &$fired): void {
            $loop->onReadable($null, function (string $id) use ($loop, &$fired): void {
                $fired['null']++;
                $loop->cancel($id);
            });

            $loop->onReadable($a, function (string $id, $stream) use ($loop, &$fired): void {
                $fired['socket']++;
                \fread($stream, 8192);
                $loop->cancel($id);
            });
        });

        self::assertSame(['null' => 1, 'socket' => 1], $fired);
    }

    public function testCharacterDeviceStreamGetsItsCallback(): void
    {
        $null = \fopen('/dev/null', 'r');
        $fired = 0;

        $this->runBounded(function (Driver $loop) use ($null, &$fired): void {
            $loop->onReadable($null, function (string $id) use ($loop, &$fired): void {
                $fired++;
                $loop->cancel($id);
            });
        });

        self::assertSame(1, $fired);
    }

    public function testStdinGetsItsCallbackWhenItIsAlwaysReadable(): void
    {
        $stat = \fstat(\STDIN);
        $isCharDevice = \is_array($stat) && (($stat['mode'] & 0170000) === 0020000);

        if (\stream_isatty(\STDIN) || !$isCharDevice) {
            self::markTestSkipped('STDIN is not /dev/null here, so readiness cannot be guaranteed');
        }

        $fired = 0;

        $this->runBounded(function (Driver $loop) use (&$fired): void {
            $loop->onReadable(\STDIN, function (string $id) use ($loop, &$fired): void {
                $fired++;
                $loop->cancel($id);
            });
        });

        self::assertSame(1, $fired);
    }

    public function testSignalDeliveredWhileLoopIsBlockedInWait(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix are required to deliver signals');
        }

        $order = [];

        $this->runBounded(function (Driver $loop) use (&$order): void {
            $signalId = $loop->onSignal(\SIGUSR1, function () use (&$order): void {
                $order[] = 'signal';
            });

            $loop->delay(1.0, function () use ($loop, $signalId, &$order): void {
                $order[] = 'timer';
                $loop->cancel($signalId);
            });

            // The loop is parked in its wait for the 1 second timer when the child delivers the signal.
            $loop->defer(static function (): void {
                \proc_open('sh -c "sleep 0.3; kill -USR1 ' . \getmypid() . '"', [], $pipes);
            });
        });

        self::assertSame(['signal', 'timer'], $order);
    }

    public function testSignalDispatchedFromTheLoopWithoutAsyncSignals(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix are required to deliver signals');
        }

        \pcntl_async_signals(true);

        try {
            $invoked = false;

            $this->runBounded(function (Driver $loop) use (&$invoked): void {
                $signalId = $loop->onSignal(\SIGUSR1, function () use (&$invoked): void {
                    $invoked = true;
                });

                $loop->defer(function () use ($loop, $signalId): void {
                    \posix_kill(\getmypid(), \SIGUSR1);

                    $loop->defer(function () use ($loop, $signalId): void {
                        $loop->defer(function () use ($loop, $signalId): void {
                            $loop->cancel($signalId);
                        });
                    });
                });
            });

            self::assertTrue($invoked);
        } finally {
            \pcntl_async_signals(false);
        }
    }

    public function testPeerCloseWakesReadableCallbackWithEof(): void
    {
        [$a, $b] = $this->socketPair();
        \fwrite($b, 'bye');

        $received = '';
        $eof = false;

        $this->runBounded(function (Driver $loop) use ($a, $b, &$received, &$eof): void {
            $loop->onReadable($a, function (string $id, $stream) use ($loop, &$received, &$eof): void {
                $chunk = \fread($stream, 8192);
                if ($chunk !== false) {
                    $received .= $chunk;
                }

                if ($chunk === '' && \feof($stream)) {
                    $eof = true;
                    $loop->cancel($id);
                }
            });

            $loop->defer(static function () use ($b): void {
                \fclose($b);
            });
        });

        self::assertSame('bye', $received);
        self::assertTrue($eof, 'The readable callback must fire for a hung up peer and see EOF');
    }
}
