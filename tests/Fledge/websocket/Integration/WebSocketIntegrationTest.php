<?php

use Fledge\Async\WebSocket\Client\WebsocketHandshake;

use function Fledge\Async\WebSocket\Client\connect;

function websocketUrl(): string
{
    return test_env('FLEDGE_TEST_WEBSOCKET_URL', 'ws://127.0.0.1:18081');
}

function websocketAvailable(): bool
{
    $parts = parse_url(websocketUrl());
    $sock = @fsockopen($parts['host'] ?? '127.0.0.1', $parts['port'] ?? 18081, $errno, $errstr, 1);
    if (! $sock) {
        return false;
    }
    fclose($sock);

    return true;
}

uses()->beforeEach(function () {
    if (! websocketAvailable()) {
        $this->markTestSkipped('WebSocket echo server not available at '.websocketUrl());
    }
});

it('connects, sends text, and receives echo', function () {
    $connection = connect(websocketUrl());

    // Consume welcome message from jmalloc/echo-server
    $welcome = $connection->receive();

    $connection->sendText('hello fledge');

    $message = $connection->receive();
    expect($message)->not->toBeNull();
    expect($message->buffer())->toBe('hello fledge');

    $connection->close();
});

it('sends multiple messages and receives echoes', function () {
    $connection = connect(websocketUrl());

    // Consume welcome message
    $connection->receive();

    $messages = ['first', 'second', 'third'];

    foreach ($messages as $msg) {
        $connection->sendText($msg);
        $received = $connection->receive();
        expect($received)->not->toBeNull();
        expect($received->buffer())->toBe($msg);
    }

    $connection->close();
});
