<?php

declare(strict_types=1);

use Fledge\Async\Http\Http2\Http2Parser;

require_once __DIR__.'/../Fixtures/h2raw.php';

it('resets a stream that sends more DATA than the advertised window', function () {
    $peer = startH2Driver();

    try {
        $peer->openStream(1);

        // 4 x 16384 = 65536 bytes, one more than the 65535 byte stream window.
        for ($i = 0; $i < 4; $i++) {
            $peer->sendFrame(Http2Parser::DATA, 0, 1, str_repeat('a', 16384));
        }

        expect($peer->resetCode(1))->toBe(Http2Parser::FLOW_CONTROL_ERROR);
    } finally {
        $peer->close();
    }
})->group('Http2');

it('closes the connection when DATA exceeds the connection window', function () {
    $peer = startH2Driver();

    try {
        $peer->openStream(1);

        // Let the server process the HEADERS frame first (it tops up a low connection window).
        $peer->sendFrame(Http2Parser::PING, 0, 0, '12345678');
        $peer->waitFor(fn (array $f) => $f['type'] === Http2Parser::PING && ($f['flags'] & Http2Parser::ACK) !== 0);

        $window = new ReflectionProperty($peer->driver, 'serverWindow');
        $window->setValue($peer->driver, 10);

        $peer->sendFrame(Http2Parser::DATA, 0, 1, str_repeat('a', 100));

        expect($peer->goAwayCode())->toBe(Http2Parser::FLOW_CONTROL_ERROR);
    } finally {
        $peer->close();
    }
})->group('Http2');

it('does not reset a stream within the window when the peer advertises a small initial window', function () {
    $peer = startH2Driver();

    try {
        // Peer SETTINGS_INITIAL_WINDOW_SIZE = 0 limits what the server may send, not what it receives.
        $peer->sendFrame(Http2Parser::SETTINGS, 0, 0, pack('nN', Http2Parser::INITIAL_WINDOW_SIZE, 0));
        $peer->openStream(1);
        $peer->sendFrame(Http2Parser::DATA, 0, 1, str_repeat('a', 1000));

        expect($peer->resetCode(1, 0.5))->toBeNull();
    } finally {
        $peer->close();
    }
})->group('Http2');
