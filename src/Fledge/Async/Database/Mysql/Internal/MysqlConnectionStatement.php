<?php declare(strict_types=1);

namespace Fledge\Async\Database\Mysql\Internal;

use Fledge\Async\DeferredFuture;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Database\Mysql\MysqlResult;
use Fledge\Async\Database\Mysql\MysqlStatement;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Database\SqlQueryError;
use Revolt\EventLoop;

/** @internal */
final class MysqlConnectionStatement implements MysqlStatement
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly int $totalParamCount;
    private readonly int $positionalParamCount;

    private array $named = [];

    /** @var list<int> positional index => real parameter id */
    private array $positionalIds = [];

    /** @var array<string> */
    private array $prebound = [];

    private ?ConnectionProcessor $processor;

    private int $lastUsedAt;

    private readonly DeferredFuture $onClose;

    public function __construct(
        ConnectionProcessor $processor,
        private readonly string $query,
        private readonly int $statementId,
        private readonly array $byNamed,
        private readonly MysqlResultProxy $result
    ) {
        $this->processor = $processor;
        $this->totalParamCount = $this->result->columnsToFetch;

        $this->onClose = new DeferredFuture();

        $positionalParamCount = $this->totalParamCount;
        foreach ($this->byNamed as $name => $ids) {
            foreach ($ids as $id) {
                $this->named[$id] = $name;
                $positionalParamCount--;
            }
        }

        $this->positionalParamCount = $positionalParamCount;

        for ($realId = 0; $realId < $this->totalParamCount; $realId++) {
            if (!isset($this->named[$realId])) {
                $this->positionalIds[] = $realId;
            }
        }

        $this->lastUsedAt = \time();
    }

    private function getProcessor(): ConnectionProcessor
    {
        if ($this->processor === null) {
            throw new \Error("The statement has been closed");
        }

        if ($this->processor->isClosed()) {
            throw new SqlConnectionException("Connection went away");
        }

        return $this->processor;
    }

    public function isClosed(): bool
    {
        return !$this->processor || $this->processor->isClosed();
    }

    public function close(): void
    {
        if ($this->processor) {
            self::shutdown($this->processor, $this->statementId, $this->onClose);
            $this->processor = null;
        }
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose)->ignore();
    }

    public function bind(int|string $paramId, string $data): void
    {
        if (\is_int($paramId)) {
            if ($paramId >= $this->positionalParamCount || $paramId < 0) {
                throw new \Error("Parameter $paramId is not defined for this prepared statement");
            }

            $this->getProcessor()->bindParam($this->statementId, $this->positionalIds[$paramId], $data);
            $this->prebound[$paramId] = ($this->prebound[$paramId] ?? '') . $data;

            return;
        } else {
            if (!isset($this->byNamed[$paramId])) {
                throw new \Error("Named parameter :$paramId is not defined for this prepared statement");
            }
        }

        foreach ($this->byNamed[$paramId] as $realId) {
            $this->getProcessor()->bindParam($this->statementId, $realId, $data);
        }

        $prior = $this->prebound[$paramId] ?? '';
        $this->prebound[$paramId] = $prior . $data;
    }

    public function execute(array $params = []): MysqlResult
    {
        $this->lastUsedAt = \time();

        // Long data wins over an arg for the same parameter: the server skips the value of a parameter
        // that has long data, so a value sent anyway would land on the next parameter.
        $prebound = $args = [];
        for ($unnamed = $i = 0; $i < $this->totalParamCount; $i++) {
            if (isset($this->named[$i])) {
                $name = $this->named[$i];
                if (\array_key_exists($name, $this->prebound)) {
                    $prebound[$i] = $this->prebound[$name];
                } elseif (\array_key_exists($name, $params)) {
                    $args[$i] = $params[$name];
                } else {
                    throw new \Error("Named parameter '$name' missing for executing prepared statement");
                }
            } elseif (\array_key_exists($unnamed, $this->prebound)) {
                $prebound[$i] = $this->prebound[$unnamed++];
            } elseif (\array_key_exists($unnamed, $params)) {
                $args[$i] = $params[$unnamed++];
            } else {
                throw new \Error("Parameter $unnamed missing for executing prepared statement");
            }
        }

        // PREPARE_OK completes prepare() before the definitions arrive. Queue right away, behind the writes
        // of earlier binds: the task reads the definitions when it runs, once all of them are in.
        $processor = $this->getProcessor();

        // The server drops long data once an execute reaches it, so these binds are used up.
        $hadBinds = $this->prebound !== [];
        $this->prebound = [];

        try {
            return $processor
                ->execute($this->statementId, $this->query, $this->result, $prebound, $args)
                ->await();
        } catch (SqlQueryError|SqlConnectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            // The execute never left, for example on a value that can't be encoded: the server still
            // holds the long data, and would use it for the next execute.
            if ($hadBinds) {
                try {
                    $this->reset();
                } catch (\Throwable) {
                    // The connection is gone, and the long data with it.
                }
            }

            throw $exception;
        }
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function reset(): void
    {
        $this->prebound = [];

        $this->getProcessor()
            ->resetStmt($this->statementId)
            ->await();
    }

    public function getColumnDefinitions(): array
    {
        return $this->result->getColumnDefinitions();
    }

    public function getParameterDefinitions(): array
    {
        return $this->result->getParameterDefinitions();
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    public function __destruct()
    {
        if ($this->processor) {
            EventLoop::queue(self::shutdown(...), $this->processor, $this->statementId, $this->onClose);
        }
    }

    private static function shutdown(ConnectionProcessor $processor, int $stmtId, DeferredFuture $onClose): void
    {
        try {
            $processor->closeStmt($stmtId);
            $processor->unreference();
        } finally {
            $onClose->complete();
        }
    }
}
