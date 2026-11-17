<?php

declare(strict_types=1);

use Fledge\Async\Future;
use Fledge\Async\TimeoutCancellation;
use Fledge\Async\WebSocket\Rfc6455Client;
use Fledge\Async\WebSocket\Server\WebsocketClientGateway;
use Fledge\Async\WebSocket\WebsocketCloseCode;
use Fledge\Async\WebSocket\WebsocketClosedException;
use Revolt\EventLoop;
use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Stream\createSocketPair;

/*
 * Server side send queue behind WebsocketClientGateway: sends to a closing client resolve instead of hanging, and a
 * peer that stops reading is disconnected once its queue passes the configured bound.
 */

function sendQueueTestClient(float $closePeriod = 1): array
{
    [$server, $peer] = createSocketPair();

    return [new Rfc6455Client($server, masked: false, closePeriod: $closePeriod), $peer];
}

/** Awaits with a timeout, so a send that never resolves fails the test instead of blocking it. */
function sendQueueTestFailure(Future $future): ?Throwable
{
    try {
        $future->await(new TimeoutCancellation(1));
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

it('errors a send to a client that is closing instead of leaving it pending', function () {
    [$client, $peer] = sendQueueTestClient();
    $gateway = new WebsocketClientGateway();
    $gateway->addClient($client);
    delay(0); // The drain fiber starts and suspends on the empty queue.

    $closing = async($client->close(...));
    delay(0);

    // Closed, but still waiting for the peer close frame, so the gateway still holds the sender.
    expect($client->isClosed())->toBeTrue()
        ->and($gateway->getClients())->toHaveKey($client->getId());

    $failure = sendQueueTestFailure($gateway->sendText('late', $client->getId()));

    expect($failure)->toBeInstanceOf(WebsocketClosedException::class)
        ->and($failure->getCode())->toBe(WebsocketCloseCode::NORMAL_CLOSE);

    $peer->close();
    $closing->await();
});

it('errors sends still queued when the client closes during an in-flight write', function () {
    [$client, $peer] = sendQueueTestClient(closePeriod: 2);
    $gateway = new WebsocketClientGateway();
    $gateway->addClient($client);

    // The peer does not read yet, so this write fills the socket buffer and blocks the drain fiber.
    $first = $gateway->sendBinary(\str_repeat('a', 4 * 1024 * 1024), $client->getId());
    $second = $gateway->sendBinary('b', $client->getId());
    delay(0.05);

    expect($first->isComplete())->toBeFalse();

    $closing = async($client->close(...));
    delay(0);

    expect($client->isClosed())->toBeTrue();

    // The peer now drains the socket, so the in-flight write finishes after the client was closed.
    async(static function () use ($peer): void {
        while ($peer->read() !== null) {
            // Discard.
        }
    })->ignore();

    expect(sendQueueTestFailure($first))->toBeNull()
        ->and(sendQueueTestFailure($second))->toBeInstanceOf(WebsocketClosedException::class);

    $peer->close();
    $closing->await();
});

it('closes a client that stops reading once its queued bytes pass the limit', function () {
    [$client, $peer] = sendQueueTestClient(closePeriod: 0.1);
    $gateway = new WebsocketClientGateway(maxQueuedBytes: 64 * 1024);
    $gateway->addClient($client);

    // Enqueued without yielding, so nothing drains: eight 8 KiB messages fit, the ninth passes 64 KiB.
    $futures = [];
    for ($i = 0; $i < 9; $i++) {
        $futures[] = $gateway->sendBinary(\str_repeat('x', 8 * 1024), $client->getId());
    }

    $late = $gateway->sendBinary('after overflow', $client->getId());
    delay(0.01);

    foreach ([...$futures, $late] as $future) {
        $failure = sendQueueTestFailure($future);

        expect($failure)->toBeInstanceOf(WebsocketClosedException::class)
            ->and($failure->getCode())->toBe(WebsocketCloseCode::POLICY_VIOLATION);
    }

    expect($client->isClosed())->toBeTrue()
        ->and($client->getCloseInfo()->getCode())->toBe(WebsocketCloseCode::POLICY_VIOLATION);

    $peer->close();
});

it('closes a client that stops reading once its queued message count passes the limit', function () {
    [$client, $peer] = sendQueueTestClient(closePeriod: 0.1);
    $gateway = new WebsocketClientGateway(maxQueuedMessages: 3);
    $gateway->addClient($client);

    $futures = [];
    for ($i = 0; $i < 4; $i++) {
        $futures[] = $gateway->sendText('m', $client->getId());
    }
    delay(0.01);

    foreach ($futures as $future) {
        expect(sendQueueTestFailure($future)?->getCode())->toBe(WebsocketCloseCode::POLICY_VIOLATION);
    }

    expect($client->getCloseInfo()->getCode())->toBe(WebsocketCloseCode::POLICY_VIOLATION);

    $peer->close();
});

it('delivers a burst to a reading client under the default limits without closing it', function () {
    [$client, $peer] = sendQueueTestClient();
    $gateway = new WebsocketClientGateway();
    $gateway->addClient($client);

    async(static function () use ($peer): void {
        while ($peer->read() !== null) {
            // Discard.
        }
    })->ignore();

    // 2,000 messages of 1 KiB enqueued at once: far under 16 MiB and 65,536 messages.
    $futures = [];
    for ($i = 0; $i < 2000; $i++) {
        $futures[] = $gateway->sendText(\str_repeat('m', 1024), $client->getId());
    }

    foreach ($futures as $future) {
        expect(sendQueueTestFailure($future))->toBeNull();
    }

    expect($client->isClosed())->toBeFalse();

    $peer->close();
});

it('delivers a message larger than the byte limit to an idle reading client', function () {
    [$client, $peer] = sendQueueTestClient();
    $gateway = new WebsocketClientGateway(maxQueuedBytes: 64 * 1024);
    $gateway->addClient($client);

    async(static function () use ($peer): void {
        while ($peer->read() !== null) {
            // Discard.
        }
    })->ignore();
    delay(0);

    expect(sendQueueTestFailure($gateway->sendText(\str_repeat('x', 256 * 1024), $client->getId())))->toBeNull()
        ->and($client->isClosed())->toBeFalse();

    $peer->close();
});

it('overflows a message queued behind an oversized message still in flight', function () {
    [$client, $peer] = sendQueueTestClient(closePeriod: 0.1);
    $gateway = new WebsocketClientGateway(maxQueuedBytes: 64 * 1024);
    $gateway->addClient($client);

    // Admitted on an idle queue; the peer does not read, so it stays in flight.
    $first = $gateway->sendBinary(\str_repeat('a', 4 * 1024 * 1024), $client->getId());
    delay(0.05);

    expect($first->isComplete())->toBeFalse();

    $second = sendQueueTestFailure($gateway->sendBinary(\str_repeat('b', 128 * 1024), $client->getId()));
    delay(0.01);

    expect($second?->getCode())->toBe(WebsocketCloseCode::POLICY_VIOLATION)
        ->and($client->getCloseInfo()->getCode())->toBe(WebsocketCloseCode::POLICY_VIOLATION);

    $peer->close();
});

it('raises no unhandled future errors when a write fails with dropped send futures', function () {
    [$client, $peer] = sendQueueTestClient(closePeriod: 0.1);
    $gateway = new WebsocketClientGateway();
    $gateway->addClient($client);

    $previousHandler = EventLoop::getErrorHandler();
    $errors = [];
    EventLoop::setErrorHandler(static function (\Throwable $error) use (&$errors): void {
        $errors[] = $error;
    });

    try {
        // Neither future is kept: the first blocks in flight, the second queues behind it.
        $gateway->sendBinary(\str_repeat('a', 4 * 1024 * 1024), $client->getId());
        $gateway->sendBinary('b', $client->getId());
        delay(0.05);

        // The peer goes away, so the in-flight write fails.
        $peer->close();
        delay(0.3);
        \gc_collect_cycles();
        delay(0.01);
    } finally {
        EventLoop::setErrorHandler($previousHandler);
    }

    expect($client->isClosed())->toBeTrue()
        ->and(\array_map(static fn (\Throwable $error): string => $error::class, $errors))->toBe([]);
});

it('does not bound the queue when both limits are null', function () {
    [$client, $peer] = sendQueueTestClient(closePeriod: 0.1);
    $gateway = new WebsocketClientGateway(maxQueuedBytes: null, maxQueuedMessages: null);
    $gateway->addClient($client);

    for ($i = 0; $i < 100; $i++) {
        $gateway->sendBinary(\str_repeat('x', 64 * 1024), $client->getId())->ignore();
    }
    delay(0.01);

    expect($client->isClosed())->toBeFalse();

    $peer->close();
});
