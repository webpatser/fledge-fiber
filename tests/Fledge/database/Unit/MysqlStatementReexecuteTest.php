<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\MysqlConnection;
use Fledge\Async\Database\Mysql\SocketMysqlConnector;
use Fledge\Async\Database\SqlQueryError;
use Fledge\Fiber\Database\Pdo\FledgePdoStatement;
use Revolt\EventLoop;

use function Fledge\Async\delay;

const STATEMENT_REEXECUTE_U1 = '11111111-1111-4111-8111-111111111111';

const STATEMENT_REEXECUTE_U2 = '22222222-2222-4222-8222-222222222222';

/**
 * An in-process MySQL server that records what a statement sends on each execute. A real server
 * drops long data once an execute reaches it, so the frames show what the next execute relies on.
 *
 * Every watcher here is unreferenced, as in MysqlLongParameterListTest.
 */
final class StatementReexecuteFakeServer
{
    /** @var list<string> Every command after the handshake, in arrival order. */
    public array $commands = [];

    /** Answer the next execute with this ERR payload. */
    public ?string $failNextExecute = null;

    /** Answer the next execute with a one-column result set of this many rows. */
    public int $rowsOnNextExecute = 0;

    /** Close the socket instead of answering a COM_STMT_RESET. */
    public bool $dropOnReset = false;

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

    public function __construct()
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

    /**
     * Forget the commands so far, typically the prepare.
     */
    public function forgetCommands(): void
    {
        $this->commands = [];
    }

    /**
     * The first byte of each command since the last forgetCommands().
     *
     * @return list<int>
     */
    public function commandBytes(): array
    {
        return array_map(static fn (string $payload): int => ord($payload[0]), $this->commands);
    }

    /**
     * The parameter ids of the COM_STMT_SEND_LONG_DATA frames.
     *
     * @return list<int>
     */
    public function longDataParamIds(): array
    {
        return array_values(array_map(
            static fn (string $payload): int => unpack('v', substr($payload, 5, 2))[1],
            $this->commandsOf(0x18),
        ));
    }

    /**
     * The values of the COM_STMT_SEND_LONG_DATA frames.
     *
     * @return list<string>
     */
    public function longDataValues(): array
    {
        return array_values(array_map(
            static fn (string $payload): string => substr($payload, 7),
            $this->commandsOf(0x18),
        ));
    }

    /**
     * The value bytes of the last COM_STMT_EXECUTE, after the header, null bitmap, bound flag and types.
     */
    public function lastExecuteValues(int $parameters): string
    {
        $executes = array_values($this->commandsOf(0x17));

        return substr(end($executes), 10 + (($parameters + 7) >> 3) + 1 + 2 * $parameters);
    }

    public function stop(): void
    {
        EventLoop::cancel($this->acceptWatcher);

        if ($this->readWatcher !== null) {
            EventLoop::cancel($this->readWatcher);
        }

        if (is_resource($this->client)) {
            fclose($this->client);
        }

        fclose($this->listener);
    }

    /**
     * @return array<int, string>
     */
    private function commandsOf(int $command): array
    {
        return array_filter($this->commands, static fn (string $payload): bool => ord($payload[0]) === $command);
    }

    private function read(): void
    {
        $chunk = fread($this->client, 1 << 20);
        if ($chunk === false || ($chunk === '' && feof($this->client))) {
            EventLoop::cancel($this->readWatcher);
            $this->readWatcher = null;

            return;
        }

        $this->buffer .= $chunk;

        while (strlen($this->buffer) >= 4) {
            $length = unpack('V', substr($this->buffer, 0, 3)."\0")[1];
            if (strlen($this->buffer) - 4 < $length) {
                return;
            }

            $this->sequence = ord($this->buffer[3]);
            $payload = substr($this->buffer, 4, $length);
            $this->buffer = substr($this->buffer, 4 + $length);

            $this->handle($payload);
        }
    }

    private function handle(string $payload): void
    {
        if (! $this->authenticated) {
            $this->authenticated = true;
            $this->reply($this->ok());

            return;
        }

        $command = ord($payload[0]);

        if ($command !== 0x01) {
            $this->commands[] = $payload;
        }

        // COM_QUIT: the client waits for the socket to close.
        if ($command === 0x01 || ($command === 0x1a && $this->dropOnReset)) {
            EventLoop::cancel($this->readWatcher);
            $this->readWatcher = null;
            fclose($this->client);
            $this->client = null;
            $this->buffer = '';

            return;
        }

        match ($command) {
            0x18, 0x19 => null,
            0x03, 0x1a => $this->reply($this->ok()),
            0x16 => $this->prepare(substr($payload, 1)),
            0x17 => $this->execute(),
            default => throw new RuntimeException('Unexpected client command '.$command),
        };
    }

    private function prepare(string $query): void
    {
        $parameters = substr_count($query, '?');

        $this->reply(
            "\x00".pack('V', $this->nextStatementId++).pack('v', 0).pack('v', $parameters)."\x00".pack('v', 0),
            ...array_fill(0, $parameters, $this->definition()),
            ...[$this->eof()],
        );
    }

    private function execute(): void
    {
        $rows = $this->rowsOnNextExecute;
        $this->rowsOnNextExecute = 0;

        if ($rows > 0) {
            // Row header, a null bitmap for one column, then the value.
            $this->reply("\x01", $this->definition(), $this->eof(), ...array_fill(0, $rows, "\x00\x00\x01r"), ...[$this->eof()]);

            return;
        }

        $reply = $this->failNextExecute ?? $this->ok();
        $this->failNextExecute = null;

        $this->reply($reply);
    }

    private function reply(string ...$payloads): void
    {
        foreach ($payloads as $payload) {
            $this->sequence++;
            fwrite($this->client, $this->packet($payload));
        }
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
 * Run a scenario with a watchdog, on its own suspension, as in MysqlLongParameterListTest.
 *
 * @param  Closure(MysqlConnection, StatementReexecuteFakeServer):void  $scenario
 */
function runStatementReexecuteScenario(Closure $scenario, float $timeout = 15): void
{
    $server = new StatementReexecuteFakeServer;
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

    $watchdog = EventLoop::unreference(EventLoop::delay($timeout, static function () use ($settle): void {
        $settle(new RuntimeException('The scenario hung until the watchdog fired'));
    }));

    try {
        $failure = $suspension->suspend();
    } catch (Throwable $exception) {
        $waiting = false;
        $failure = $exception;
    }

    EventLoop::cancel($watchdog);
    $server->stop();

    if ($failure !== null) {
        $errorHandler = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static function (): void {});

        try {
            delay(0.1);
            gc_collect_cycles();
            delay(0);
        } finally {
            EventLoop::setErrorHandler($errorHandler);
        }

        throw $failure;
    }
}

const STATEMENT_REEXECUTE_SERVER_ERROR = "\xff\xda\x04#21000Subquery returns more than 1 row";

describe('a raw statement', function () {
    it('throws on a re-execute without rebinding instead of sending NULL', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?');
            $statement->bind(0, 'A');
            $statement->execute();
            $server->forgetCommands();

            expect(static fn () => $statement->execute())->toThrow(Error::class, 'Parameter 0 missing')
                ->and($server->commands)->toBeEmpty();
        });
    });

    it('throws on a named re-execute without rebinding', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT :a');
            $statement->bind('a', 'A');
            $statement->execute();
            $server->forgetCommands();

            expect(static fn () => $statement->execute())->toThrow(Error::class, "Named parameter 'a' missing")
                ->and($server->commands)->toBeEmpty();
        });
    });

    it('sends the long data of a new bind on the next execute', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?');
            $statement->bind(0, 'one');
            $statement->execute();
            $server->forgetCommands();

            $statement->bind(0, 'two');
            $statement->execute();

            expect($server->commandBytes())->toBe([0x18, 0x17])
                ->and($server->longDataValues())->toBe(['two'])
                ->and($server->lastExecuteValues(1))->toBe('');

            // And that bind is used up as well.
            expect(static fn () => $statement->execute())->toThrow(Error::class, 'Parameter 0 missing');
        });
    });

    it('forgets its binds on reset', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?');
            $statement->bind(0, 'one');
            $statement->reset();
            $server->forgetCommands();

            expect(static fn () => $statement->execute())->toThrow(Error::class, 'Parameter 0 missing')
                ->and($server->commands)->toBeEmpty();
        });
    });

    it('still accepts plain args on every execute', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?');
            $statement->execute(['a']);
            $statement->execute(['b']);

            expect($server->lastExecuteValues(1))->toBe("\x01b")
                ->and(static fn () => $statement->execute())->toThrow(Error::class, 'Parameter 0 missing');
        });
    });

    it('needs the bound parameter again when a mixed execute runs twice', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?, ?');
            $statement->bind(0, 'B0');
            $statement->execute([1 => 'a1']);

            expect($server->lastExecuteValues(2))->toBe("\x02a1");

            $server->forgetCommands();

            expect(static fn () => $statement->execute([1 => 'a1']))->toThrow(Error::class, 'Parameter 0 missing')
                ->and($server->commands)->toBeEmpty();
        });
    });

    it('sends no value for a bound parameter that also has an arg', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?, ?');
            $statement->bind(0, 'BOUND');
            $statement->execute(['y', 'z']);

            // The server skips the value of a parameter with long data, so a value here would land on
            // the next parameter: this is what returned BOUND, y on a real server.
            expect($server->longDataParamIds())->toBe([0])
                ->and($server->lastExecuteValues(2))->toBe("\x01z");
        });
    });

    it('drops long data the server still holds when an execute cannot be encoded', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?, ?');
            $server->forgetCommands();
            $statement->bind(0, 'LONG');

            expect(static fn () => $statement->execute([1 => new stdClass]))->toThrow(TypeError::class, 'stdClass')
                ->and($server->commandBytes())->toBe([0x18, 0x1a])
                ->and(static fn () => $statement->execute([1 => 'a']))->toThrow(Error::class, 'Parameter 0 missing');
        });
    });

    it('leaves nothing bound after the server rejects an execute', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?');
            $statement->bind(0, 'LONG');
            $server->failNextExecute = STATEMENT_REEXECUTE_SERVER_ERROR;

            expect(static fn () => $statement->execute())->toThrow(SqlQueryError::class, '1242');

            $server->forgetCommands();

            expect(static fn () => $statement->execute())->toThrow(Error::class, 'Parameter 0 missing')
                ->and($server->commands)->toBeEmpty();
        });
    });

    it('sends no reset after the server rejects an execute, since the long data is gone already', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?');
            $server->forgetCommands();
            $statement->bind(0, 'LONG');
            $server->failNextExecute = STATEMENT_REEXECUTE_SERVER_ERROR;

            expect(static fn () => $statement->execute())->toThrow(SqlQueryError::class, '1242')
                ->and($server->commandBytes())->toBe([0x18, 0x17]);
        });
    });

    it('sends no reset after an encoding failure when nothing was bound', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?');
            $server->forgetCommands();

            expect(static fn () => $statement->execute([new stdClass]))->toThrow(TypeError::class, 'stdClass')
                ->and($server->commands)->toBeEmpty();

            // The connection took no reset, and still answers.
            $statement->execute(['next']);

            expect($server->commandBytes())->toBe([0x17]);
        });
    });

    it('reports the encoding failure when the reset after it fails too', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?, ?');
            $server->forgetCommands();
            $statement->bind(0, 'LONG');
            $server->dropOnReset = true;

            expect(static fn () => $statement->execute([1 => new stdClass]))->toThrow(TypeError::class, 'stdClass')
                ->and($server->commandBytes())->toBe([0x18, 0x1a]);
        });
    });

    it('keeps its binds when an execute fails on a missing parameter, since nothing was sent', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?, ?');
            $server->forgetCommands();
            $statement->bind(0, 'x');

            expect(static fn () => $statement->execute())->toThrow(Error::class, 'Parameter 1 missing');

            $statement->bind(1, 'y');
            $statement->execute();

            expect($server->longDataParamIds())->toBe([0, 1])
                ->and($server->commandBytes())->toBe([0x18, 0x18, 0x17]);
        });
    });
});

describe('a repeated named parameter', function () {
    it('sends long data to every position', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT :a, :a, :a');
            $statement->bind('a', 'N');
            $statement->execute();

            expect($server->longDataParamIds())->toBe([0, 1, 2])
                ->and($server->longDataValues())->toBe(['N', 'N', 'N'])
                ->and($server->lastExecuteValues(3))->toBe('');
        });
    });

    it('finds the real positions in a mix with positional parameters', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT ?, :a, ?, :b, :a');
            $statement->bind(0, 'p0');
            $statement->bind('a', 'na');
            $statement->bind(1, 'p1');
            $statement->bind('b', 'nb');
            $statement->execute();

            expect($server->longDataParamIds())->toBe([0, 1, 4, 2, 3])
                ->and($server->longDataValues())->toBe(['p0', 'na', 'na', 'p1', 'nb'])
                ->and($server->lastExecuteValues(5))->toBe('');
        });
    });

    it('sends no value for a bound name that also has an arg', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT :a, ?, :a');
            $statement->bind('a', 'BOUND');
            $statement->execute(['a' => 'arg', 0 => 'p']);

            expect($server->longDataParamIds())->toBe([0, 2])
                ->and($server->lastExecuteValues(3))->toBe("\x01p");
        });
    });

    it('takes an arg for the positions next to a bound repeated name', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = $connection->prepare('SELECT :a, ?, :a');
            $statement->bind('a', 'na');
            $statement->execute(['p']);

            expect($server->longDataParamIds())->toBe([0, 2])
                ->and($server->lastExecuteValues(3))->toBe("\x01p");
        });
    });
});

describe('a PDO statement', function () {
    it('costs no extra round trip on a single execute', function (array $params, array $frames) {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server) use ($params, $frames): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT ?'));
            $server->forgetCommands();

            $statement->execute($params);

            expect($server->commandBytes())->toBe($frames);
        });
    })->with([
        'a uuid' => [[STATEMENT_REEXECUTE_U1], [0x18, 0x17]],
        'a plain value' => [['plain'], [0x17]],
    ]);

    it('resets once, then sends the new uuid, on a second execute', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT ?'));
            $statement->execute([STATEMENT_REEXECUTE_U1]);
            $server->forgetCommands();

            $statement->execute([STATEMENT_REEXECUTE_U2]);

            expect($server->commandBytes())->toBe([0x1a, 0x18, 0x17])
                ->and($server->longDataValues())->toBe([STATEMENT_REEXECUTE_U2]);
        });
    });

    it('sends a plain value after a uuid without long data', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT ?'));
            $statement->execute([STATEMENT_REEXECUTE_U1]);
            $server->forgetCommands();

            $statement->execute(['plain']);

            expect($server->commandBytes())->toBe([0x1a, 0x17])
                ->and($server->lastExecuteValues(1))->toBe("\x05plain");
        });
    });

    it('sends the same values again on an execute without params', function (Closure $first, array $longData, string $values) {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server) use ($first, $longData, $values): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT ?'));
            $first($statement);
            $server->forgetCommands();

            $statement->execute();

            expect($server->longDataValues())->toBe($longData)
                ->and($server->lastExecuteValues(1))->toBe($values);
        });
    })->with([
        'a bound uuid' => [static function (FledgePdoStatement $statement): void {
            $statement->bindValue(1, STATEMENT_REEXECUTE_U1);
            $statement->execute();
        }, [STATEMENT_REEXECUTE_U1], ''],
        'a uuid arg' => [static fn (FledgePdoStatement $statement) => $statement->execute([STATEMENT_REEXECUTE_U1]), [STATEMENT_REEXECUTE_U1], ''],
        'a bound plain value' => [static function (FledgePdoStatement $statement): void {
            $statement->bindValue(1, 'plain');
            $statement->execute();
        }, [], "\x05plain"],
        'a plain arg' => [static fn (FledgePdoStatement $statement) => $statement->execute(['plain']), [], "\x05plain"],
    ]);

    it('lets a bindValue replace a value that came in through execute', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT ?'));
            $statement->execute([STATEMENT_REEXECUTE_U1]);
            $server->forgetCommands();

            $statement->bindValue(1, STATEMENT_REEXECUTE_U2);
            $statement->execute();

            expect($server->commandBytes())->toBe([0x1a, 0x18, 0x17])
                ->and($server->longDataValues())->toBe([STATEMENT_REEXECUTE_U2]);
        });
    });

    it('rebinds one parameter and keeps the other', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT ?, ?'));
            $statement->bindValue(1, STATEMENT_REEXECUTE_U1);
            $statement->bindValue(2, 'keep');
            $statement->execute();
            $server->forgetCommands();

            $statement->bindValue(1, STATEMENT_REEXECUTE_U2);
            $statement->execute();

            expect($server->longDataParamIds())->toBe([0])
                ->and($server->longDataValues())->toBe([STATEMENT_REEXECUTE_U2])
                ->and($server->lastExecuteValues(2))->toBe("\x04keep");
        });
    });

    it('binds a repeated name in execute args to every position', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT :a, :a'));
            $statement->execute(['a' => STATEMENT_REEXECUTE_U1]);

            expect($server->longDataParamIds())->toBe([0, 1]);

            $server->forgetCommands();
            $statement->execute(['a' => STATEMENT_REEXECUTE_U2]);

            expect($server->commandBytes())->toBe([0x1a, 0x18, 0x18, 0x17])
                ->and($server->longDataValues())->toBe([STATEMENT_REEXECUTE_U2, STATEMENT_REEXECUTE_U2]);
        });
    });

    it('collapses bound keys in key order, as keys may have gaps', function (array $bindings, array $longData, array $paramIds) {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server) use ($bindings, $longData, $paramIds): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT '.implode(', ', array_fill(0, count($bindings), '?'))));
            $server->forgetCommands();

            foreach ($bindings as [$key, $value]) {
                $statement->bindValue($key, $value);
            }

            $statement->execute();

            expect($server->longDataValues())->toBe($longData)
                ->and($server->longDataParamIds())->toBe($paramIds)
                ->and($server->commandBytes())->toBe([...array_fill(0, count($longData), 0x18), 0x17]);
        });
    })->with([
        'a gap' => [[[2, STATEMENT_REEXECUTE_U1]], [STATEMENT_REEXECUTE_U1], [0]],
        'two gaps' => [[[2, STATEMENT_REEXECUTE_U1], [5, STATEMENT_REEXECUTE_U2]], [STATEMENT_REEXECUTE_U1, STATEMENT_REEXECUTE_U2], [0, 1]],
        '0-based' => [[[0, STATEMENT_REEXECUTE_U1]], [STATEMENT_REEXECUTE_U1], [0]],
        'out of order' => [[[2, STATEMENT_REEXECUTE_U2], [1, STATEMENT_REEXECUTE_U1]], [STATEMENT_REEXECUTE_U1, STATEMENT_REEXECUTE_U2], [0, 1]],
    ]);

    it('passes execute args on without collapsing them', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT ?'));
            $server->forgetCommands();

            expect(static fn () => $statement->execute([1 => 'x']))->toThrow(Error::class, 'Parameter 0 missing')
                ->and($server->commands)->toBeEmpty();
        });
    });

    it('re-executes while the previous result has unread rows', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT ?'));
            $server->rowsOnNextExecute = 5;
            $statement->execute(['first']);
            $server->forgetCommands();

            // The read fiber waits for someone to take those rows, so a reset queued behind them only
            // runs once the result is gone.
            $server->rowsOnNextExecute = 2;
            $statement->execute(['second']);

            expect($statement->fetchAll(PDO::FETCH_NUM))->toHaveCount(2)
                ->and($server->commandBytes())->toBe([0x1a, 0x17]);
        }, timeout: 3);
    });

    it('starts clean after an execute the server rejected', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT ?'));
            $server->failNextExecute = STATEMENT_REEXECUTE_SERVER_ERROR;

            expect(static fn () => $statement->execute([STATEMENT_REEXECUTE_U1]))->toThrow(SqlQueryError::class, '1242');

            $server->forgetCommands();
            $statement->execute([STATEMENT_REEXECUTE_U2]);

            expect($server->commandBytes())->toBe([0x1a, 0x18, 0x17])
                ->and($server->longDataValues())->toBe([STATEMENT_REEXECUTE_U2]);
        });
    });

    it('starts clean after a bind failed halfway through the params', function () {
        runStatementReexecuteScenario(static function (MysqlConnection $connection, StatementReexecuteFakeServer $server): void {
            $statement = new FledgePdoStatement($connection->prepare('SELECT ?'));
            $server->forgetCommands();

            expect(static fn () => $statement->execute([STATEMENT_REEXECUTE_U1, STATEMENT_REEXECUTE_U2]))
                ->toThrow(Error::class, 'Parameter 1 is not defined');

            $statement->execute([STATEMENT_REEXECUTE_U2]);

            // The long data of the failed attempt, the reset that drops it, then the new attempt.
            expect($server->commandBytes())->toBe([0x18, 0x1a, 0x18, 0x17])
                ->and($server->longDataValues())->toBe([STATEMENT_REEXECUTE_U1, STATEMENT_REEXECUTE_U2]);
        });
    });
});
