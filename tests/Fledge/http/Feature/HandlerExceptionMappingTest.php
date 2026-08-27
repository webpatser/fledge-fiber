<?php

use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Fiber\Http\FledgeHandler;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Factory;

use function Fledge\Async\delay;

require_once __DIR__.'/../Fixtures/loopback.php';

/**
 * Minimal class-keyed event dispatcher; illuminate/events is not a
 * dependency of this package, only the contracts are.
 */
class RecordingEventDispatcher implements Dispatcher
{
    /** @var array<class-string, list<callable>> */
    public array $listeners = [];

    public function listen($events, $listener = null)
    {
        foreach ((array) $events as $event) {
            $this->listeners[$event][] = $listener;
        }
    }

    public function hasListeners($eventName)
    {
        return isset($this->listeners[$eventName]);
    }

    public function subscribe($subscriber) {}

    public function until($event, $payload = [])
    {
        return $this->dispatch($event, $payload, true);
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        foreach ($this->listeners[$event::class] ?? [] as $listener) {
            $listener($event);
        }

        return null;
    }

    public function push($event, $payload = []) {}

    public function flush($event) {}

    public function forget($event) {}

    public function forgetPushed() {}
}

it('rejects refused connections with a guzzle connect exception', function () {
    $client = makeGuzzleClient(['connect_timeout' => 2.0, 'timeout' => 2.0]);
    $port = refusedPort();

    try {
        $client->get("http://127.0.0.1:{$port}/");

        $this->fail('Expected a ConnectException for a refused port');
    } catch (ConnectException $e) {
        expect($e->getPrevious())->toBeInstanceOf(\Fledge\Async\Http\Client\HttpException::class);
    }
});

it('rejects timeouts with a guzzle connect exception', function () {
    [$server, $port] = startLoopbackServer(null, function (): ServerResponse {
        delay(1.5);

        return new ServerResponse(200, [], 'too late');
    });

    try {
        makeGuzzleClient(['timeout' => 0.5])->get("http://127.0.0.1:{$port}/");

        $this->fail('Expected a ConnectException for a timed out transfer');
    } catch (ConnectException $e) {
        expect($e->getPrevious())->toBeInstanceOf(\Fledge\Async\Http\Client\TimeoutException::class);
    } finally {
        $server->stop();
    }
});

it('raises illuminate connection exceptions and fires the ConnectionFailed event', function () {
    $events = new RecordingEventDispatcher;
    $factory = new Factory($events);

    $failed = [];
    $events->listen(ConnectionFailed::class, function (ConnectionFailed $event) use (&$failed) {
        $failed[] = $event;
    });

    $port = refusedPort();

    try {
        $factory->setHandler(new FledgeHandler)
            ->connectTimeout(2)
            ->timeout(2)
            ->get("http://127.0.0.1:{$port}/");

        $this->fail('Expected an Illuminate ConnectionException for a refused port');
    } catch (ConnectionException $e) {
        expect($e->getPrevious())->toBeInstanceOf(ConnectException::class);
    }

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception)->toBeInstanceOf(ConnectionException::class);
});
