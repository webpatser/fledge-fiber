<?php

use Fledge\Async\Redis\Connection\RedisTimeoutException;
use Fledge\Fiber\Redis\FledgeRedisConnection;
use Fledge\Fiber\Redis\FledgeRedisConnector;

/*
 * Live parity checks against redis on port 16379: client naming, ACL
 * usernames and read_timeout behavior.
 */

function parityTestParams(): array
{
    return [
        'host' => test_env('FLEDGE_TEST_REDIS_HOST', '127.0.0.1'),
        'port' => (int) test_env('FLEDGE_TEST_REDIS_PORT', 16379),
        'database' => 0,
    ];
}

function parityTestAvailable(): bool
{
    $params = parityTestParams();
    $sock = @fsockopen($params['host'], $params['port'], $errno, $errstr, 1);

    if (! $sock) {
        return false;
    }

    fclose($sock);

    return true;
}

function parityConnection(array $extra = []): FledgeRedisConnection
{
    return (new FledgeRedisConnector)->connect(parityTestParams() + $extra, []);
}

uses()->beforeEach(function () {
    if (! parityTestAvailable()) {
        $this->markTestSkipped('Redis not available on port '.test_env('FLEDGE_TEST_REDIS_PORT', 16379));
    }
});

it('sets the connection name via CLIENT SETNAME', function () {
    $connection = parityConnection(['name' => 'fledge-parity-test']);

    try {
        expect($connection->executeRaw(['CLIENT', 'GETNAME']))->toBe('fledge-parity-test');
    } finally {
        $connection->disconnect();
    }
});

it('authenticates with an ACL username and password', function () {
    $admin = parityConnection();

    try {
        $admin->executeRaw(['ACL', 'SETUSER', 'fledge_acl', 'on', '>fledge-acl-pw', '~*', '+@all']);

        $acl = parityConnection(['username' => 'fledge_acl', 'password' => 'fledge-acl-pw']);

        try {
            expect($acl->executeRaw(['ACL', 'WHOAMI']))->toBe('fledge_acl');
        } finally {
            $acl->disconnect();
        }
    } finally {
        $admin->executeRaw(['ACL', 'DELUSER', 'fledge_acl']);
        $admin->disconnect();
    }
});

it('throws a RedisTimeoutException when the server blocks past the read timeout', function () {
    $probe = parityConnection();
    $debugAvailable = true;

    try {
        $probe->executeRaw(['DEBUG', 'SLEEP', '0']);
    } catch (Throwable) {
        // DEBUG is disabled by default on modern redis; fall back to a
        // blocking BLPOP that never resolves, which stalls just this client.
        $debugAvailable = false;
    }

    $probe->disconnect();

    $blocking = $debugAvailable
        ? ['DEBUG', 'SLEEP', '2']
        : ['BLPOP', 'fledge:parity:never-pushed', '0'];

    $connection = parityConnection(['read_timeout' => 0.5]);

    try {
        $start = microtime(true);
        $connection->executeRaw($blocking);
        $this->fail('Expected RedisTimeoutException.');
    } catch (RedisTimeoutException $e) {
        expect($e->getMessage())->toContain('read error on connection')
            ->and(microtime(true) - $start)->toBeLessThan(1.5);
    } finally {
        $connection->disconnect();

        if ($debugAvailable) {
            // Give the server time to finish sleeping so later tests connect cleanly.
            \Fledge\Async\delay(2.0);
        }
    }
});
