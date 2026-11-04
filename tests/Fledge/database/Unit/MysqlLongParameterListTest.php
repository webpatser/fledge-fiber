<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnection;
use Fledge\Async\Database\Mysql\MysqlResult;
use Fledge\Async\Database\Mysql\SocketMysqlConnector;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Database\SqlQueryError;
use Fledge\Async\Stream\StreamException;
use Fledge\Async\TimeoutCancellation;
use Revolt\EventLoop;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/**
 * An in-process MySQL server that scripts the two moments the driver got wrong on long parameter
 * lists: the gap between PREPARE_OK and the last parameter definition, and a client write that
 * cannot finish because nobody reads the socket.
 *
 * Every watcher here is unreferenced. A referenced one would keep the loop alive through a client
 * write whose own watcher was unreferenced, which is exactly the bug these tests have to catch.
 */
final class LongParameterListFakeServer
{
    /** @var list<string> COM_STMT_EXECUTE payloads, in arrival order. */
    public array $executes = [];

    public int $longDataPackets = 0;

    public int $longDataBytes = 0;

    /** @var list<int> Parameter ids of COM_STMT_SEND_LONG_DATA packets, in arrival order. */
    public array $longDataParamIds = [];

    public int $closedStatements = 0;

    /** @var list<array{int, int, int}> Length, sequence id and first byte of each client packet. */
    public array $frames = [];

    /** Leave the next query, prepare or execute unanswered, for a test that scripts the reply itself. */
    public bool $ignoreNextCommand = false;

    public readonly int $port;

    /** @var resource */
    private $listener;

    /** @var resource|null */
    private $client = null;

    private string $acceptWatcher;

    private ?string $readWatcher = null;

    private string $buffer = '';

    private int $sequence = 0;

    private bool $authenticated = false;

    private int $nextStatementId = 1;

    /**
     * @param ?Closure(self, Closure():void):void $afterFirstDefinition When set, a prepare answers with
     *                                                                  PREPARE_OK and one definition only,
     *                                                                  and this decides what happens next.
     */
    public function __construct(private readonly ?Closure $afterFirstDefinition = null)
    {
        $this->listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($this->listener, false), ':'), 1);

        $this->acceptWatcher = EventLoop::unreference(EventLoop::onReadable($this->listener, function (string $id): void {
            EventLoop::cancel($id);
            $this->client = stream_socket_accept($this->listener, 0);
            stream_set_blocking($this->client, false);
            fwrite($this->client, $this->packet($this->greeting()));

            $this->readWatcher = EventLoop::unreference(EventLoop::onReadable($this->client, $this->read(...)));
        }));
    }

    public function pauseReading(): void
    {
        EventLoop::disable($this->readWatcher);
    }

    public function resumeReading(): void
    {
        EventLoop::enable($this->readWatcher);
    }

    public function dropClient(): void
    {
        if ($this->readWatcher !== null) {
            EventLoop::cancel($this->readWatcher);
            $this->readWatcher = null;
        }

        if (is_resource($this->client)) {
            fclose($this->client);
        }
        $this->client = null;
    }

    public function stop(): void
    {
        EventLoop::cancel($this->acceptWatcher);
        $this->dropClient();
        fclose($this->listener);
    }

    /**
     * Answer a prepare with PREPARE_OK and one definition.
     *
     * @return Closure():void Sends the remaining definitions.
     */
    public function answerPrepare(int $parameters): Closure
    {
        $this->reply(
            "\x00".pack('V', $this->nextStatementId++).pack('v', 0).pack('v', $parameters)."\x00".pack('v', 0),
            $this->definition(),
        );

        return fn () => $this->reply(...$this->definitionsAndEof($parameters - 1));
    }

    /**
     * Answer a query with the header of a one-column result set.
     */
    public function beginResultSet(): void
    {
        $this->reply("\x01", $this->definition(), $this->eof());
    }

    public function finishResultSet(): void
    {
        $this->reply($this->eof());
    }

    public function reply(string ...$payloads): void
    {
        fwrite($this->client, implode(array_map($this->nextPacket(...), $payloads)));
    }

    public function replyOk(): void
    {
        $this->reply($this->ok());
    }

    private function read(): void
    {
        $chunk = fread($this->client, 1 << 20);
        if ($chunk === false || ($chunk === '' && feof($this->client))) {
            $this->dropClient();

            return;
        }

        $this->buffer .= $chunk;
        $offset = 0;
        $available = strlen($this->buffer);

        while ($available - $offset >= 4) {
            $length = unpack('V', substr($this->buffer, $offset, 3)."\0")[1];
            if ($available - $offset - 4 < $length) {
                break;
            }

            $this->sequence = ord($this->buffer[$offset + 3]);
            $this->frames[] = [$length, $this->sequence, ord($this->buffer[$offset + 4] ?? "\0")];

            // A command starts at sequence 0, anything higher continues a long one.
            if (! $this->authenticated || $this->sequence === 0) {
                $this->handle(substr($this->buffer, $offset + 4, $length));
            }

            $offset += 4 + $length;

            if ($this->client === null) {
                return;
            }
        }

        if ($offset > 0) {
            $this->buffer = substr($this->buffer, $offset);
        }
    }

    private function handle(string $payload): void
    {
        if (! $this->authenticated) {
            $this->authenticated = true;
            $this->reply($this->ok());

            return;
        }

        if ($this->ignoreNextCommand && in_array(ord($payload[0]), [0x03, 0x16, 0x17], true)) {
            $this->ignoreNextCommand = false;

            return;
        }

        match (ord($payload[0])) {
            0x01 => $this->dropClient(),
            0x03 => $this->reply($this->ok()),
            0x16 => $this->prepare(substr($payload, 1)),
            0x17 => $this->execute($payload),
            0x18 => $this->longData($payload),
            0x19 => $this->closedStatements++,
            default => throw new RuntimeException('Unexpected client command '.ord($payload[0])),
        };
    }

    private function prepare(string $query): void
    {
        $parameters = substr_count($query, '?');
        $response = "\x00".pack('V', $this->nextStatementId++).pack('v', 0).pack('v', $parameters)."\x00".pack('v', 0);

        if ($this->afterFirstDefinition === null) {
            $this->reply($response, ...$this->definitionsAndEof($parameters));

            return;
        }

        $this->reply($response, $this->definition());
        ($this->afterFirstDefinition)(
            $this,
            fn () => $this->reply(...$this->definitionsAndEof($parameters - 1)),
        );
    }

    private function execute(string $payload): void
    {
        $this->executes[] = $payload;
        $this->reply($this->ok());
    }

    private function longData(string $payload): void
    {
        $this->longDataPackets++;
        $this->longDataParamIds[] = unpack('v', substr($payload, 5, 2))[1];
        $this->longDataBytes += strlen($payload) - 7; // Command byte, statement id, parameter id.
    }

    /**
     * @return list<string>
     */
    private function definitionsAndEof(int $count): array
    {
        return [...array_fill(0, $count, $this->definition()), "\xfe".pack('v', 0).pack('v', 0x0002)];
    }

    private function definition(): string
    {
        return "\x03def\0\0\0\x01?\0\x0c"
            .pack('v', 63)
            .pack('V', 255)
            ."\xfd"
            .pack('v', 0)
            ."\0\0\0";
    }

    private function ok(): string
    {
        return "\x00\x00\x00".pack('v', 0x0002).pack('v', 0);
    }

    private function eof(): string
    {
        return "\xfe".pack('v', 0).pack('v', 0x0002);
    }

    private function nextPacket(string $payload): string
    {
        $this->sequence++;

        return $this->packet($payload);
    }

    private function packet(string $payload): string
    {
        return substr(pack('V', strlen($payload)), 0, 3).chr($this->sequence & 0xff).$payload;
    }

    private function greeting(): string
    {
        $capabilities = 0x00000200 | 0x00008000 | 0x00080000; // PROTOCOL_41 | SECURE_CONNECTION | PLUGIN_AUTH

        return "\x0a"
            ."8.0.40\0"
            .pack('V', 1)
            .'abcdefgh'
            ."\0"
            .pack('v', $capabilities & 0xffff)
            ."\x21"
            .pack('v', 0x0002)
            .pack('v', $capabilities >> 16)
            .chr(21)
            .str_repeat("\0", 10)
            ."ijklmnopqrst\0"
            ."mysql_native_password\0";
    }
}

/**
 * Run a scenario with a watchdog. The timer is unreferenced, so it ends a hang without holding the
 * loop open.
 *
 * This waits on its own suspension rather than a Future. The loop reuses one suspension for {main},
 * and a Future awaited when the loop terminated would still resume it later, inside the next test.
 *
 * @param Closure(MysqlConnection, LongParameterListFakeServer):void $scenario
 * @param ?Closure(LongParameterListFakeServer, Closure():void):void $afterFirstDefinition
 */
function runLongParameterListScenario(Closure $scenario, ?Closure $afterFirstDefinition = null): void
{
    $server = new LongParameterListFakeServer($afterFirstDefinition);
    $suspension = EventLoop::getSuspension();
    $waiting = true;

    $settle = static function (?Throwable $outcome) use (&$waiting, $suspension): void {
        if ($waiting) {
            $waiting = false;
            $suspension->resume($outcome);
        }
    };

    EventLoop::queue(static function () use ($scenario, $server, $settle): void {
        try {
            $connection = (new SocketMysqlConnector)->connect(
                new MysqlConfig('127.0.0.1', $server->port, 'app', 'secret'),
            );

            $scenario($connection, $server);

            $connection->close();
            $settle(null);
        } catch (Throwable $exception) {
            $settle($exception);
        }
    });

    $watchdog = EventLoop::unreference(EventLoop::delay(15, static function () use ($settle): void {
        $settle(new RuntimeException('The scenario hung until the watchdog fired'));
    }));

    try {
        $failure = $suspension->suspend();
    } catch (Throwable $exception) {
        // The loop ran out of referenced watchers while the scenario was still waiting.
        $waiting = false;
        $failure = $exception;
    }

    EventLoop::cancel($watchdog);
    $server->stop();

    if ($failure !== null) {
        longParameterListDrainAbandonedFibers();

        throw $failure;
    }
}

/**
 * A failed scenario leaves fibers parked on the dead connection. Let them end here, so their errors
 * belong to the test that failed and do not surface in the next one.
 */
function longParameterListDrainAbandonedFibers(): void
{
    $errorHandler = EventLoop::getErrorHandler();
    EventLoop::setErrorHandler(static function (): void {});

    try {
        delay(0.1);
        gc_collect_cycles();
        delay(0);
    } finally {
        EventLoop::setErrorHandler($errorHandler);
    }
}

/**
 * Count the parameters in a COM_STMT_EXECUTE that bound only small integers: the fixed header and
 * the one-byte null bitmap take 12 bytes, then each parameter has a 2-byte type and a 1-byte value.
 */
function longParameterListExecuteParamCount(string $payload): int
{
    return (int) ((strlen($payload) - 12) / 3);
}

/**
 * The tests compare this against a baseline rather than rely on the loop stopping alone, because a
 * referenced watcher leaked by an earlier test in the same process would keep the loop running.
 */
function longParameterListReferencedWatchers(): int
{
    return count(array_filter(
        EventLoop::getIdentifiers(),
        static fn (string $id): bool => EventLoop::isEnabled($id) && EventLoop::isReferenced($id),
    ));
}

/**
 * Run a scenario that leaves its connection open and idle, then let the loop run dry. {main} only gets
 * past the suspension when nothing referenced is left, so this proves an idle connection lets the
 * process exit. A connection holding the loop open runs into the watchdog instead.
 *
 * @param Closure(MysqlConnection, LongParameterListFakeServer):void $scenario
 */
function assertLoopExitsAfterLongParameterListScenario(Closure $scenario): void
{
    $server = new LongParameterListFakeServer;
    $suspension = EventLoop::getSuspension();
    $connection = null;
    $finished = false;
    $outcome = null;

    EventLoop::queue(static function () use ($scenario, $server, $suspension, &$connection, &$finished): void {
        try {
            $connection = (new SocketMysqlConnector)->connect(
                new MysqlConfig('127.0.0.1', $server->port, 'app', 'secret'),
            );

            $scenario($connection, $server);

            $finished = true;
        } catch (Throwable $exception) {
            $suspension->resume($exception);
        }
    });

    $watchdog = EventLoop::unreference(EventLoop::delay(5, static function () use ($suspension): void {
        $suspension->resume(new RuntimeException('The idle connection kept the loop running until the watchdog fired'));
    }));

    try {
        $outcome = $suspension->suspend();
    } catch (Error $exception) {
        $outcome = $exception;
    }

    EventLoop::cancel($watchdog);

    if ($outcome instanceof RuntimeException || ! $finished) {
        $server->stop();
        longParameterListDrainAbandonedFibers();

        throw $outcome ?? new RuntimeException('The scenario did not finish');
    }

    $server->stop();
    longParameterListDrainAbandonedFibers();

    expect($outcome)->toBeInstanceOf(Error::class)
        ->and($outcome->getMessage())->toContain('terminated');
}

/**
 * Stop the server reading, then start a command that waits on the full socket buffer.
 */
function longParameterListBlockedCommand(
    MysqlConnection $connection,
    LongParameterListFakeServer $server,
    string $command,
    bool $leaveUnanswered = false,
): Fledge\Async\Future {
    $value = str_repeat('x', LONG_PARAMETER_LIST_VALUE_BYTES);
    $statement = $command === 'execute' ? $connection->prepare('SELECT ?') : null;

    $server->pauseReading();
    $server->ignoreNextCommand = $leaveUnanswered;

    return async(static fn () => $statement?->execute([$value]) ?? $connection->query($value));
}

const LONG_PARAMETER_LIST_PACKET_TOO_LARGE = "\xff\x81\x04#08S01Got a packet bigger than 'max_allowed_packet' bytes";

const LONG_PARAMETER_LIST_VALUE_BYTES = 8 * 1024 * 1024;

it('sizes the execute from every parameter definition when they arrive after PREPARE_OK', function () {
    runLongParameterListScenario(
        static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?, ?, ?');

            $result = $statement->execute([1, 2, 3]);

            expect($result)->toBeInstanceOf(MysqlResult::class)
                ->and($server->executes)->toHaveCount(1)
                ->and(longParameterListExecuteParamCount($server->executes[0]))->toBe(3);
        },
        static function (LongParameterListFakeServer $server, Closure $sendRemainingDefinitions): void {
            EventLoop::unreference(EventLoop::delay(0.05, $sendRemainingDefinitions));
        },
    );
});

it('fails the execute with a connection error when the connection dies during the definitions', function () {
    runLongParameterListScenario(
        static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?, ?, ?');

            expect(static fn () => $statement->execute([1, 2, 3]))->toThrow(SqlConnectionException::class)
                ->and($server->executes)->toBeEmpty();
        },
        static function (LongParameterListFakeServer $server): void {
            EventLoop::unreference(EventLoop::delay(0.05, $server->dropClient(...)));
        },
    );
});

it('keeps the loop alive while long data waits on a full socket buffer', function () {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
        $statement = $connection->prepare('SELECT ?, ?, ?');
        $value = str_repeat('x', LONG_PARAMETER_LIST_VALUE_BYTES);
        $idleWatchers = longParameterListReferencedWatchers();

        $server->pauseReading();

        $binds = async(static function () use ($statement, $value): void {
            $statement->bind(0, $value);
            $statement->bind(1, $value);
            $statement->bind(2, $value);
        });

        delay(0.05, reference: false);

        // Still in flight, or the buffer never filled and this test proves nothing.
        expect($binds->isComplete())->toBeFalse()
            ->and(longParameterListReferencedWatchers())->toBeGreaterThan($idleWatchers);

        $server->resumeReading();
        $binds->await();

        // The last write has finished, so the socket must not hold the loop open any more.
        expect(longParameterListReferencedWatchers())->toBe($idleWatchers);

        $result = $statement->execute();

        expect($result)->toBeInstanceOf(MysqlResult::class)
            ->and($server->longDataPackets)->toBe(3)
            ->and($server->longDataBytes)->toBe(3 * LONG_PARAMETER_LIST_VALUE_BYTES)
            ->and($server->executes)->toHaveCount(1);
    });
});

it('keeps the loop alive when a statement closes while long data is blocked', function () {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
        $statement = $connection->prepare('SELECT ?, ?');
        $abandoned = $connection->prepare('SELECT ?');
        $value = str_repeat('x', LONG_PARAMETER_LIST_VALUE_BYTES);
        $idleWatchers = longParameterListReferencedWatchers();

        $server->pauseReading();

        // The write queue is first bind, close, second bind. The first bind and the close finish while
        // the second bind is still blocked, so neither may release the socket underneath it.
        $firstBind = async(static fn () => $statement->bind(0, $value));
        unset($abandoned);
        $secondBind = async(static fn () => $statement->bind(1, $value));

        delay(0.05, reference: false);

        // Still in flight, or the buffer never filled and this test proves nothing.
        expect($firstBind->isComplete())->toBeFalse()
            ->and($secondBind->isComplete())->toBeFalse()
            ->and(longParameterListReferencedWatchers())->toBeGreaterThan($idleWatchers);

        $server->resumeReading();
        $firstBind->await();
        $secondBind->await();

        // The last write has finished, so the socket must not hold the loop open any more.
        expect(longParameterListReferencedWatchers())->toBe($idleWatchers);

        $result = $statement->execute();

        expect($result)->toBeInstanceOf(MysqlResult::class)
            ->and($server->closedStatements)->toBe(1)
            ->and($server->longDataPackets)->toBe(2)
            ->and($server->longDataBytes)->toBe(2 * LONG_PARAMETER_LIST_VALUE_BYTES)
            ->and($server->executes)->toHaveCount(1);
    });
});

it('maps positional bind ids around named parameters to the real parameter ids', function () {
    runLongParameterListScenario(
        static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?, :a, ?, :b, ?');

            $statement->bind(0, 'p0');
            $statement->bind(1, 'p1');
            $statement->bind(2, 'p2');
            $statement->bind('b', 'nb');
            $statement->bind('a', 'na');
            $statement->execute();

            expect($server->longDataParamIds)->toBe([0, 2, 4, 3, 1]);
        },
    );
});

it('fails a waiting execute when a write queued behind its prepare loses the connection', function (bool $closeStatementToo) {
    $release = null;
    $prepares = 0;

    runLongParameterListScenario(
        static function (MysqlConnection $connection, LongParameterListFakeServer $server) use (&$release, $closeStatementToo): void {
            $other = $connection->prepare('SELECT ?');
            $abandoned = $connection->prepare('SELECT ?');
            $statement = $connection->prepare('SELECT ?, ?');

            // Queued behind the definitions of the last prepare, so they will run inside the read fiber.
            $other->bind(0, str_repeat('x', LONG_PARAMETER_LIST_VALUE_BYTES));

            if ($closeStatementToo) {
                unset($abandoned);
            }

            $execute = async(static fn () => $statement->execute([1, 2]));

            delay(0.05, reference: false);

            $server->pauseReading();
            $release();

            delay(0.1, reference: false);

            // Still in flight, or the buffer never filled and this test proves nothing.
            expect($execute->isComplete())->toBeFalse();

            $server->dropClient();

            expect(static fn () => $execute->await())->toThrow(SqlConnectionException::class);
        },
        static function (LongParameterListFakeServer $server, Closure $sendRemainingDefinitions) use (&$release, &$prepares): void {
            if (++$prepares === 3) {
                $release = $sendRemainingDefinitions;
            } else {
                $sendRemainingDefinitions();
            }
        },
    );
})->with(['a bind' => false, 'a bind and a statement close' => true]);

it('finishes the rows of a result while a write queued behind it is blocked', function () {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
        $statement = $connection->prepare('SELECT ?');

        $server->ignoreNextCommand = true;
        $pending = async(static fn () => $connection->query('SELECT 1'));
        delay(0.05, reference: false);
        $server->beginResultSet();
        $result = $pending->await();

        // Queued behind the rows, so it runs inside the read fiber when the result set ends.
        $statement->bind(0, str_repeat('x', LONG_PARAMETER_LIST_VALUE_BYTES));

        $server->pauseReading();
        $server->finishResultSet();

        $rows = async(static fn () => iterator_to_array($result));

        expect($rows->await(new TimeoutCancellation(2)))->toBe([]);

        $server->resumeReading();
    });
});

it('reports the server error when ERR and FIN arrive during a blocked write', function (string $command) {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server) use ($command): void {
        $idleWatchers = longParameterListReferencedWatchers();

        $blocked = longParameterListBlockedCommand($connection, $server, $command);

        delay(0.05, reference: false);

        expect($blocked->isComplete())->toBeFalse();

        $server->reply(LONG_PARAMETER_LIST_PACKET_TOO_LARGE);
        EventLoop::unreference(EventLoop::delay(0.02, $server->dropClient(...)));

        expect(static fn () => $blocked->await())->toThrow(SqlQueryError::class, '1153')
            ->and(longParameterListReferencedWatchers())->toBe($idleWatchers);
    });
})->with(['execute', 'query']);

it('holds the socket until a write that outlived its command finishes, then releases it', function (string $command) {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server) use ($command): void {
        $idleWatchers = longParameterListReferencedWatchers();

        $blocked = longParameterListBlockedCommand($connection, $server, $command, leaveUnanswered: true);

        delay(0.05, reference: false);

        $server->reply(LONG_PARAMETER_LIST_PACKET_TOO_LARGE);

        delay(0.05, reference: false);

        // The command has its answer, its write does not know yet.
        expect($blocked->isComplete())->toBeFalse()
            ->and(longParameterListReferencedWatchers())->toBeGreaterThan($idleWatchers);

        $server->resumeReading();

        expect(static fn () => $blocked->await())->toThrow(SqlQueryError::class, '1153')
            ->and(longParameterListReferencedWatchers())->toBe($idleWatchers);

        // Nothing of the failed command is left behind on the connection.
        expect($connection->query('SELECT 1'))->toBeInstanceOf(MysqlResult::class)
            ->and(longParameterListReferencedWatchers())->toBe($idleWatchers);
    });
})->with(['execute', 'query']);

it('lets the loop exit after a write that outlived its command', function (string $command) {
    assertLoopExitsAfterLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server) use ($command): void {
        $blocked = longParameterListBlockedCommand($connection, $server, $command, leaveUnanswered: true);

        delay(0.05, reference: false);

        $server->reply(LONG_PARAMETER_LIST_PACKET_TOO_LARGE);

        delay(0.05, reference: false);

        $server->resumeReading();

        expect(static fn () => $blocked->await())->toThrow(SqlQueryError::class, '1153');
    });
})->with(['execute', 'query']);

it('lets the loop exit after long data and a statement close', function () {
    assertLoopExitsAfterLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
        $statement = $connection->prepare('SELECT ?');
        $abandoned = $connection->prepare('SELECT ?');

        $server->pauseReading();
        $bind = async(static fn () => $statement->bind(0, str_repeat('x', LONG_PARAMETER_LIST_VALUE_BYTES)));
        unset($abandoned);

        delay(0.05, reference: false);

        $server->resumeReading();
        $bind->await();
    });
});

it('keeps the socket referenced after the writes finish while a reply is outstanding', function () {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
        $statement = $connection->prepare('SELECT ?');
        $idleWatchers = longParameterListReferencedWatchers();

        $server->pauseReading();
        $server->ignoreNextCommand = true;

        $bind = async(static fn () => $statement->bind(0, str_repeat('x', LONG_PARAMETER_LIST_VALUE_BYTES)));
        $execute = async(static fn () => $statement->execute(['small']));

        delay(0.05, reference: false);

        $server->resumeReading();
        $bind->await();

        delay(0.05, reference: false);

        // Every write is done, the execute still waits for its reply.
        expect($execute->isComplete())->toBeFalse()
            ->and(longParameterListReferencedWatchers())->toBeGreaterThan($idleWatchers);

        $server->replyOk();
        $execute->await();

        expect(longParameterListReferencedWatchers())->toBe($idleWatchers);
    });
});

it('keeps the socket referenced after a write finishes while definitions are outstanding', function () {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
        $idleWatchers = longParameterListReferencedWatchers();

        $server->pauseReading();
        $server->ignoreNextCommand = true;

        // A prepare this long is still being written when the early reply below arrives.
        $prepare = async(static fn () => $connection->prepare('SELECT ?, ? /* '.str_repeat('x', LONG_PARAMETER_LIST_VALUE_BYTES).' */'));

        delay(0.05, reference: false);

        $sendRemainingDefinitions = $server->answerPrepare(2);

        delay(0.05, reference: false);

        $server->resumeReading();

        $statement = $prepare->await();

        delay(0.05, reference: false);

        // The write is done and no deferred is queued, only the definitions are still on their way.
        expect(longParameterListReferencedWatchers())->toBeGreaterThan($idleWatchers);

        $sendRemainingDefinitions();

        $execute = async(static fn () => $statement->execute([1, 2]));

        expect($execute->await())->toBeInstanceOf(MysqlResult::class)
            ->and(longParameterListReferencedWatchers())->toBe($idleWatchers);
    });
});

it('fails the command with a connection error when the peer drops during its blocked write', function (string $command) {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server) use ($command): void {
        $idleWatchers = longParameterListReferencedWatchers();

        $blocked = longParameterListBlockedCommand($connection, $server, $command);

        delay(0.05, reference: false);

        expect($blocked->isComplete())->toBeFalse();

        $server->dropClient();

        expect(static fn () => $blocked->await())->toThrow(SqlConnectionException::class)
            ->and(longParameterListReferencedWatchers())->toBe($idleWatchers);
    });
})->with(['execute', 'query']);

it('survives the peer dropping while a statement close waits behind blocked long data', function () {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
        $statement = $connection->prepare('SELECT ?');
        $abandoned = $connection->prepare('SELECT ?');
        $idleWatchers = longParameterListReferencedWatchers();

        $server->pauseReading();

        $bind = async(static fn () => $statement->bind(0, str_repeat('x', LONG_PARAMETER_LIST_VALUE_BYTES)));
        unset($abandoned);

        delay(0.05, reference: false);

        expect($bind->isComplete())->toBeFalse();

        $server->dropClient();

        expect(static fn () => $bind->await())->toThrow(StreamException::class);

        // A close failing on the loop would surface here as an uncaught error.
        delay(0.05);

        expect(longParameterListReferencedWatchers())->toBe($idleWatchers);
    });
});

it('finishes a blocked long data write before a graceful close', function () {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
        $statement = $connection->prepare('SELECT ?');
        $idleWatchers = longParameterListReferencedWatchers();

        $server->pauseReading();

        $bind = async(static fn () => $statement->bind(0, str_repeat('x', LONG_PARAMETER_LIST_VALUE_BYTES)));
        $close = async(static fn () => $connection->close());

        delay(0.05, reference: false);

        expect($bind->isComplete())->toBeFalse()
            ->and($close->isComplete())->toBeFalse();

        $server->resumeReading();
        $bind->await();
        $close->await();

        expect($server->longDataBytes)->toBe(LONG_PARAMETER_LIST_VALUE_BYTES)
            ->and(longParameterListReferencedWatchers())->toBe($idleWatchers);
    });
});

it('fails a graceful close with a connection error when the peer drops during its blocked write', function () {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
        $statement = $connection->prepare('SELECT ?');
        $idleWatchers = longParameterListReferencedWatchers();

        $server->pauseReading();

        $bind = async(static fn () => $statement->bind(0, str_repeat('x', LONG_PARAMETER_LIST_VALUE_BYTES)));
        $close = async(static fn () => $connection->close());

        delay(0.05, reference: false);

        $server->dropClient();

        expect(static fn () => $bind->await())->toThrow(StreamException::class)
            ->and(static fn () => $close->await())->toThrow(SqlConnectionException::class)
            ->and(longParameterListReferencedWatchers())->toBe($idleWatchers);
    });
});

it('keeps the two packets of a long bind together when execute follows during the definitions', function () {
    runLongParameterListScenario(
        static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?');
            $server->frames = [];

            $statement->bind(0, str_repeat('a', 0xffffff + 1000));
            $statement->execute();

            expect($server->frames)->toBe([
                [0xffffff, 0, 0x18],
                [1007, 1, ord('a')],
                [14, 0, 0x17],
            ]);
        },
        static function (LongParameterListFakeServer $server, Closure $sendRemainingDefinitions): void {
            EventLoop::unreference(EventLoop::delay(0.05, $sendRemainingDefinitions));
        },
    );
});

it('gives a queued execute its encoding error and keeps the connection usable', function () {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
        $statement = $connection->prepare('SELECT ?');
        $idleWatchers = longParameterListReferencedWatchers();

        $server->ignoreNextCommand = true;
        $first = async(static fn () => $connection->query('SELECT 1'));

        delay(0.05, reference: false);

        // Both wait behind the unanswered query, so the encoding fails inside the read fiber.
        $invalid = async(static fn () => $statement->execute([new stdClass]));
        $next = async(static fn () => $connection->query('SELECT 2'));

        delay(0.05, reference: false);

        expect($invalid->isComplete())->toBeFalse();

        $server->replyOk();

        $first->await();

        expect(static fn () => $invalid->await(new TimeoutCancellation(2)))->toThrow(TypeError::class, 'stdClass')
            ->and($next->await(new TimeoutCancellation(2)))->toBeInstanceOf(MysqlResult::class)
            ->and($connection->isClosed())->toBeFalse()
            ->and($server->executes)->toBeEmpty()
            ->and(longParameterListReferencedWatchers())->toBe($idleWatchers);
    });
});

it('gives a directly started execute its encoding error and keeps the connection usable', function () {
    runLongParameterListScenario(static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
        $statement = $connection->prepare('SELECT ?');
        $idleWatchers = longParameterListReferencedWatchers();

        expect(static fn () => $statement->execute([new stdClass]))->toThrow(TypeError::class, 'stdClass')
            ->and($connection->query('SELECT 1'))->toBeInstanceOf(MysqlResult::class)
            ->and(longParameterListReferencedWatchers())->toBe($idleWatchers);
    });
});

it('fails a queued execute when an ERR arrives in the middle of the definitions', function () {
    runLongParameterListScenario(
        static function (MysqlConnection $connection, LongParameterListFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?, ?');
            $execute = async(static fn () => $statement->execute([1, 2]));

            delay(0.05, reference: false);

            // The definitions parser takes this for a definition and fails, which closes the connection.
            $server->reply("\xff\x28\x04#HY000Definitions went wrong");

            expect(static fn () => $execute->await(new TimeoutCancellation(2)))->toThrow(SqlConnectionException::class)
                ->and($server->executes)->toBeEmpty();
        },
        static function (): void {},
    );
});
