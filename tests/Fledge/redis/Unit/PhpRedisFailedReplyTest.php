<?php

use Fledge\Async\Redis\Connection\RedisConnectionException;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisClient;
use Fledge\Fiber\Redis\FledgeRedisConnection;

/*
 * phpredis puts false in the slot of a failed command inside EXEC and nested
 * EVAL or raw replies. Laravel code written for phpredis relies on that, so the
 * connection must not hand out the QueryException objects the parser keeps.
 */

/**
 * Answers each command from a fixed reply table, QUEUED inside MULTI.
 */
final class ScriptedReplyLink implements RedisLink
{
    private bool $inMulti = false;

    /**
     * @param  array<string, RedisResponse>  $replies
     */
    public function __construct(private array $replies)
    {
    }

    public function execute(string $command, array $parameters): RedisResponse
    {
        $command = strtoupper($command);

        if (isset($this->replies[$command])) {
            $this->inMulti = $command === 'MULTI' ? true : ($command === 'EXEC' ? false : $this->inMulti);

            return $this->replies[$command];
        }

        $reply = match (true) {
            $command === 'MULTI' => 'OK',
            $this->inMulti => 'QUEUED',
            default => 'OK',
        };

        $this->inMulti = match ($command) {
            'MULTI' => true,
            'EXEC', 'DISCARD' => false,
            default => $this->inMulti,
        };

        return new RedisValue($reply);
    }
}

function scriptedConnection(array $replies): FledgeRedisConnection
{
    return new FledgeRedisConnection(new RedisClient(new ScriptedReplyLink($replies)));
}

$execReply = fn () => new RedisValue(['OK', new QueryException('ERR value is not an integer or out of range'), 'v']);

it('puts false in the slot of a failed command in a transaction callback', function () use ($execReply) {
    $connection = scriptedConnection(['EXEC' => $execReply()]);

    $result = $connection->transaction(function ($redis) {
        $redis->set('k', 'v');
        $redis->incr('k');
        $redis->get('k');
    });

    expect($result)->toBe(['OK', false, 'v']);
});

it('puts false in the slot of a failed command for multi and exec', function () use ($execReply) {
    $connection = scriptedConnection(['EXEC' => $execReply()]);

    $connection->multi();
    $connection->set('k', 'v');
    $connection->incr('k');
    $connection->get('k');

    expect($connection->exec())->toBe(['OK', false, 'v']);
});

it('maps nested error replies of eval to false at any depth', function () {
    $connection = scriptedConnection([
        'EVALSHA' => new RedisValue([1, new QueryException('ERR inner'), [2, new QueryException('ERR deep')]]),
    ]);

    expect($connection->eval('return 1', 0))->toBe([1, false, [2, false]]);
});

it('maps nested error replies of the eval fallback to false', function () {
    $connection = scriptedConnection([
        'EVALSHA' => new RedisError('NOSCRIPT No matching script'),
        'EVAL' => new RedisValue(['a', new QueryException('ERR inner')]),
    ]);

    expect($connection->eval('return 1', 0))->toBe(['a', false]);
});

it('maps nested error replies of a raw command to false', function () {
    $connection = scriptedConnection([
        'EXEC' => new RedisValue([new QueryException('ERR inner'), 3]),
    ]);

    expect($connection->executeRaw(['EXEC']))->toBe([false, 3]);
});

it('returns false for a rejected queued command and the aborted exec', function () {
    $connection = scriptedConnection([
        'GET' => new RedisError('ERR wrong number of arguments for \'get\' command'),
        'EXEC' => new RedisError('EXECABORT Transaction discarded because of previous errors.'),
    ]);

    $connection->multi();

    expect($connection->set('k', 'v'))->toBe($connection)
        ->and($connection->get('k'))->toBeFalse()
        ->and($connection->exec())->toBeFalse()
        ->and($connection->ownsTransaction())->toBeFalse();
});

it('still throws on a connection error at exec', function () {
    $link = new class implements RedisLink
    {
        public function execute(string $command, array $parameters): RedisResponse
        {
            if (strtoupper($command) === 'EXEC') {
                throw new RedisConnectionException('Connection lost');
            }

            return new RedisValue('OK');
        }
    };
    $connection = new FledgeRedisConnection(new RedisClient($link));

    $connection->multi();

    expect(fn () => $connection->exec())->toThrow(RedisConnectionException::class, 'Connection lost')
        ->and($connection->ownsTransaction())->toBeFalse();
});

it('returns false for a watch-aborted exec', function () {
    $connection = scriptedConnection(['EXEC' => new RedisValue(null)]);

    $connection->multi();
    $connection->set('k', 'v');

    expect($connection->exec())->toBeFalse();
});

it('maps nil replies inside exec to false at any depth', function () {
    $connection = scriptedConnection(['EXEC' => new RedisValue([null, 'v', ['a', null]])]);

    $connection->multi();
    $connection->get('missing');
    $connection->get('k');
    $connection->mget(['a', 'b']);

    expect($connection->exec())->toBe([false, 'v', ['a', false]]);
});

it('returns false for a nil eval result and nil table elements', function () {
    expect(scriptedConnection(['EVALSHA' => new RedisValue(null)])->eval('return nil', 0))->toBeFalse()
        ->and(scriptedConnection(['EVALSHA' => new RedisValue([1, null])])->eval('return 1', 0))->toBe([1, false]);
});

it('maps nested error replies of a command passed through __call to false', function () {
    $connection = scriptedConnection(['FCALL' => new RedisValue([1, new QueryException('ERR inner')])]);

    expect($connection->fcall('f', 0))->toBe([1, false]);
});

it('still throws on a top-level error reply outside a transaction', function () {
    scriptedConnection(['FCALL' => new RedisError('ERR Function not found')])->fcall('f', 0);
})->throws(QueryException::class, 'Function not found');
