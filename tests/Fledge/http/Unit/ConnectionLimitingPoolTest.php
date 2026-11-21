<?php

use Fledge\Async\Cancellation;
use Fledge\Async\CancelledException;
use Fledge\Async\DeferredCancellation;
use Fledge\Async\Http\Client\Connection\Connection;
use Fledge\Async\Http\Client\Connection\ConnectionFactory;
use Fledge\Async\Http\Client\Connection\ConnectionLimitingPool;
use Fledge\Async\Http\Client\Connection\Http1Connection;
use Fledge\Async\Http\Client\HttpClientBuilder;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\NullCancellation;
use Fledge\Fiber\Http\FledgeHandler;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Fledge\Loop;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Http\Client\events;
use function Fledge\Async\Stream\createSocketPair;

/**
 * Connection factory over socket pairs with a tiny HTTP/1.1 server on the far
 * end. The server answers every request with "ok" after an optional delay and
 * stops when the client side closes. It counts how many client connections
 * are open at once, so a test can prove the pool limit is never exceeded.
 */
function poolTestFactory(float $responseDelay = 0.0): ConnectionFactory
{
    return new class($responseDelay) implements ConnectionFactory
    {
        public int $created = 0;

        public int $open = 0;

        public int $maxOpen = 0;

        public int $served = 0;

        /** @var list<\Fledge\Async\Stream\Socket> */
        public array $servers = [];

        public function __construct(private readonly float $responseDelay) {}

        public function create(Request $request, Cancellation $cancellation): Connection
        {
            [$server, $client] = createSocketPair();

            $this->created++;
            $this->open++;
            $this->maxOpen = max($this->maxOpen, $this->open);
            $this->servers[] = $server;

            async(function () use ($server): void {
                $buffer = '';

                try {
                    while (null !== $chunk = $server->read()) {
                        $buffer .= $chunk;

                        while (($end = strpos($buffer, "\r\n\r\n")) !== false) {
                            $buffer = substr($buffer, $end + 4);

                            if ($this->responseDelay > 0) {
                                delay($this->responseDelay);
                            }

                            $server->write("HTTP/1.1 200 OK\r\nConnection: keep-alive\r\nContent-Length: 2\r\n\r\nok");
                            $this->served++;
                        }
                    }
                } catch (\Throwable) {
                    // The client went away mid-response.
                } finally {
                    $server->close();
                }
            })->ignore();

            $connection = new Http1Connection($client, 0.0, null, 5.0);

            // Count from the client side: registered before the pool's own onClose
            // callback, so it runs before the pool frees the slot.
            $connection->onClose(function (): void {
                $this->open--;
            });

            return $connection;
        }

        public function closeAll(): void
        {
            foreach ($this->servers as $server) {
                $server->close();
            }
        }
    };
}

function poolTestRequest(): Request
{
    $request = new Request('http://localhost/');
    events()->requestStart($request);

    return $request;
}

function poolTestWaiting(ConnectionLimitingPool $pool): array
{
    return (new ReflectionProperty($pool, 'waiting'))->getValue($pool);
}

it('stops waiting for a connection slot when the request is cancelled', function () {
    $factory = poolTestFactory();
    $pool = ConnectionLimitingPool::byAuthority(1, $factory);

    $streamA = $pool->getStream(poolTestRequest(), new NullCancellation);

    $cancelB = new DeferredCancellation;
    $requestB = poolTestRequest();
    $futureB = async(fn () => $pool->getStream($requestB, $cancelB->getCancellation()));

    delay(0.01);
    expect($futureB->isComplete())->toBeFalse();

    $cancelB->cancel();
    delay(0.01);

    expect($futureB->isComplete())->toBeTrue()
        ->and(fn () => $futureB->await())->toThrow(CancelledException::class)
        ->and(poolTestWaiting($pool))->toBe([]);

    // The slot is still usable: releasing A lets a new request reuse the one connection.
    unset($streamA);
    delay(0.01);

    $streamC = $pool->getStream(poolTestRequest(), new NullCancellation);

    expect($streamC)->not->toBeNull()
        ->and($factory->created)->toBe(1);

    unset($streamC);
    $factory->closeAll();
});

it('hands a released connection to the next waiter when the first waiter was cancelled', function () {
    $factory = poolTestFactory();
    $pool = ConnectionLimitingPool::byAuthority(1, $factory);

    $streamA = $pool->getStream(poolTestRequest(), new NullCancellation);

    $cancelB = new DeferredCancellation;
    $requestB = poolTestRequest();
    $futureB = async(fn () => $pool->getStream($requestB, $cancelB->getCancellation()));

    $requestC = poolTestRequest();
    $futureC = async(fn () => $pool->getStream($requestC, new NullCancellation));

    delay(0.01);

    // Cancel B, then release A before B's fiber has resumed to leave the queue.
    $cancelB->cancel();
    Loop::queue(function () use (&$streamA): void {
        $streamA = null;
    });

    delay(0.05);

    expect(fn () => $futureB->await())->toThrow(CancelledException::class)
        ->and($futureC->isComplete())->toBeTrue()
        ->and($futureC->await())->not->toBeNull()
        ->and($factory->created)->toBe(1)
        ->and(poolTestWaiting($pool))->toBe([]);

    $factory->closeAll();
});

it('never opens more connections than the limit and loses no queued request', function () {
    $factory = poolTestFactory(0.02);
    $pool = ConnectionLimitingPool::byAuthority(2, $factory);

    $client = (new HttpClientBuilder)
        ->retry(0)
        ->usingPool($pool)
        ->build();

    $cancellations = [];
    $futures = [];

    foreach (range(1, 6) as $i) {
        $cancellations[$i] = new DeferredCancellation;
        $futures[$i] = async(fn () => $client->request(new Request('http://localhost/'), $cancellations[$i]->getCancellation())
            ->getBody()
            ->buffer());
    }

    // Requests 4 and 5 are queued behind the two busy connections; cancel them there.
    delay(0.005);
    $cancellations[4]->cancel();
    $cancellations[5]->cancel();

    $results = [];

    foreach ($futures as $i => $future) {
        try {
            $results[$i] = $future->await(new \Fledge\Async\TimeoutCancellation(5));
        } catch (CancelledException) {
            $results[$i] = 'cancelled';
        }
    }

    expect($results)->toBe([1 => 'ok', 2 => 'ok', 3 => 'ok', 4 => 'cancelled', 5 => 'cancelled', 6 => 'ok'])
        ->and($factory->maxOpen)->toBeLessThanOrEqual(2)
        ->and($factory->created)->toBeLessThanOrEqual(2)
        ->and(poolTestWaiting($pool))->toBe([]);

    $factory->closeAll();
});

it('counts the wait for a pool slot against the Guzzle timeout', function () {
    $factory = poolTestFactory(0.5);
    $pool = ConnectionLimitingPool::byAuthority(1, $factory);

    $handler = new FledgeHandler(
        (new HttpClientBuilder)->retry(0)->usingPool($pool)->build()
    );

    $promiseA = $handler(new Psr7Request('GET', 'http://localhost/a'), []);
    $promiseB = $handler(new Psr7Request('GET', 'http://localhost/b'), ['timeout' => 0.1]);

    // A's body is only read once A is waited on, so A keeps the slot until then.
    // A request that ignores its timeout while queued would wait forever: fail instead of hanging.
    $watchdog = Loop::delay(3, static function (): void {
        throw new \RuntimeException('Request B was still queued 3 s after its 0.1 s timeout');
    });

    try {
        expect(fn () => $promiseB->wait())->toThrow(ConnectException::class, 'Allowed transfer timeout exceeded');
    } finally {
        Loop::cancel($watchdog);
    }

    // B timed out while still queued: A, which holds the only slot, has not been answered yet.
    expect($factory->served)->toBe(0);

    $responseA = $promiseA->wait();

    expect((string) $responseA->getBody())->toBe('ok')
        ->and($factory->served)->toBe(1)
        ->and($factory->created)->toBe(1);

    $factory->closeAll();
});
