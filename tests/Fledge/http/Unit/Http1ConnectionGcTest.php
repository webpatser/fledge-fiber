<?php

use Fledge\Async\Http\Client\Connection\Connection;
use Fledge\Async\Http\Client\Connection\ConnectionFactory;
use Fledge\Async\Http\Client\Connection\ConnectionLimitingPool;
use Fledge\Async\Http\Client\Connection\Http1Connection;
use Fledge\Async\Http\Client\HttpClientBuilder;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\SocketException;
use Fledge\Async\NullCancellation;

use function Fledge\Async\delay;
use function Fledge\Async\Http\Client\events;
use function Fledge\Async\Stream\createSocketPair;

it('removes the waiting deferred when a connection attempt fails', function () {
    $factory = new class implements ConnectionFactory
    {
        public function create(Request $request, \Fledge\Async\Cancellation $cancellation): Connection
        {
            throw new SocketException('Connection failed');
        }
    };

    $pool = ConnectionLimitingPool::byAuthority(1, $factory);

    $client = (new HttpClientBuilder)
        ->retry(0)
        ->usingPool($pool)
        ->build();

    try {
        $client->request(new Request('http://localhost'));
        $this->fail('Connection attempt should have failed');
    } catch (SocketException) {
        // Expected.
    }

    delay(0);

    $waiting = (new ReflectionProperty($pool, 'waiting'))->getValue($pool);

    expect($waiting)->toBe([]);
});

it('garbage collects an idle keep-alive Http1Connection once strong refs are dropped', function () {
    try {
        [$server, $client] = createSocketPair();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Socket pair unavailable: '.$e->getMessage());
    }

    $connection = new Http1Connection($client, 0.0, null, 5.0);
    $connectionRef = \WeakReference::create($connection);

    $request = new Request('http://localhost');
    events()->requestStart($request);

    $stream = $connection->getStream($request);
    expect($stream)->not->toBeNull();

    $server->write("HTTP/1.1 204 No Content\r\nConnection: keep-alive\r\nContent-Length: 0\r\n\r\n");

    $response = $stream->request($request, new NullCancellation);
    $response->getBody()->buffer();

    // Drop every strong reference to the connection and its socket.
    unset($client, $connection, $request, $response, $stream);

    do {
        delay(0);
    } while (\gc_collect_cycles());

    expect($connectionRef->get())->toBeNull();

    $server->close();
});
