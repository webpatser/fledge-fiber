<?php

declare(strict_types=1);

use Fledge\Async\Http\Http2\Http2Parser;
use Fledge\Async\Http\Server\Driver\HttpDriver;

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

it('closes the connection with ENHANCE_YOUR_CALM on a flood of PRIORITY frames', function () {
    $peer = startH2Driver();

    try {
        $priority = pack('NC', 0, 15);
        $frame = Http2Parser::compileFrame($priority, Http2Parser::PRIORITY, 0, 1);

        $peer->send(str_repeat($frame, HttpDriver::DEFAULT_LOW_VALUE_FRAME_LIMIT + 1));

        expect($peer->goAwayCode())->toBe(Http2Parser::ENHANCE_YOUR_CALM);
    } finally {
        $peer->close();
    }
})->group('Http2');

it('closes the connection with ENHANCE_YOUR_CALM on a flood of empty DATA frames', function () {
    $peer = startH2Driver();

    try {
        $peer->openStream(1);
        $frame = Http2Parser::compileFrame('', Http2Parser::DATA, 0, 1);

        $peer->send(str_repeat($frame, HttpDriver::DEFAULT_LOW_VALUE_FRAME_LIMIT + 1));

        expect($peer->goAwayCode())->toBe(Http2Parser::ENHANCE_YOUR_CALM);
    } finally {
        $peer->close();
    }
})->group('Http2');

it('does not count ordinary frames toward the low-value limit', function () {
    $peer = startH2Driver();

    try {
        $ping = Http2Parser::compileFrame('12345678', Http2Parser::PING, Http2Parser::ACK, 0);

        // PING acks are ignored by the driver and must not trip the flood limit.
        $peer->send(str_repeat($ping, HttpDriver::DEFAULT_LOW_VALUE_FRAME_LIMIT + 1));

        expect($peer->goAwayCode(0.5))->toBeNull();
    } finally {
        $peer->close();
    }
})->group('Http2');

it('trips a lower configured low-value frame limit earlier', function () {
    $peer = startH2Driver(['lowValueFrameLimit' => 50]);

    try {
        $frame = Http2Parser::compileFrame(pack('NC', 0, 15), Http2Parser::PRIORITY, 0, 1);

        $peer->send(str_repeat($frame, 51));

        expect($peer->goAwayCode())->toBe(Http2Parser::ENHANCE_YOUR_CALM);
    } finally {
        $peer->close();
    }
})->group('Http2');

it('does not trip a higher configured low-value frame limit at the default count', function () {
    $peer = startH2Driver(['lowValueFrameLimit' => HttpDriver::DEFAULT_LOW_VALUE_FRAME_LIMIT * 2]);

    try {
        $frame = Http2Parser::compileFrame(pack('NC', 0, 15), Http2Parser::PRIORITY, 0, 1);

        $peer->send(str_repeat($frame, HttpDriver::DEFAULT_LOW_VALUE_FRAME_LIMIT + 1));

        expect($peer->goAwayCode(0.5))->toBeNull();
    } finally {
        $peer->close();
    }
})->group('Http2');

it('rejects a content-length with trailing garbage', function (string $value) {
    $peer = startH2Driver();

    try {
        $peer->openStream(1, [['content-length', $value]]);

        expect($peer->resetCode(1))->toBe(Http2Parser::PROTOCOL_ERROR);
    } finally {
        $peer->close();
    }
})->with(['0abc', '1e3', '-1', '01', ' 5'])->group('Http2');

it('accepts a valid content-length', function () {
    $peer = startH2Driver();

    try {
        $peer->openStream(1, [['content-length', '0']], true);

        expect($peer->resetCode(1, 0.5))->toBeNull();
    } finally {
        $peer->close();
    }
})->group('Http2');

it('enforces the header list size it advertises in SETTINGS', function () {
    $limit = 4096;
    $peer = startH2Driver(['headerSizeLimit' => $limit]);

    try {
        $settings = $peer->waitFor(fn (array $f) => $f['type'] === Http2Parser::SETTINGS && ! ($f['flags'] & Http2Parser::ACK));
        $advertised = null;
        foreach (str_split($settings['payload'], 6) as $entry) {
            ['id' => $id, 'value' => $value] = unpack('nid/Nvalue', $entry);
            if ($id === Http2Parser::MAX_HEADER_LIST_SIZE) {
                $advertised = $value;
            }
        }

        expect($advertised)->toBe($limit);

        // A header block just under the limit is accepted.
        $peer->openStream(1, [['x-pad', substr(hash('sha512', 'a').hash('sha512', 'b').hash('sha512', 'c'), 0, 3000)]]);
        expect($peer->goAwayCode(0.5))->toBeNull();

        // A header block above the advertised limit is refused.
        $big = '';
        for ($i = 0; strlen($big) < 8000; $i++) {
            $big .= hash('sha512', (string) $i);
        }
        $peer->openStream(3, [['x-pad', $big]]);
        expect($peer->goAwayCode())->toBe(Http2Parser::COMPRESSION_ERROR);
    } finally {
        $peer->close();
    }
})->group('Http2');

it('does not leave an unobserved future when a stream is reset before its request exists', function () {
    $peer = startH2Driver();

    $errors = [];
    \Revolt\EventLoop::setErrorHandler(function (\Throwable $e) use (&$errors): void {
        $errors[] = $e;
    });

    try {
        $peer->openStream(1, [['content-length', '1'], ['content-length', '2']]);

        expect($peer->resetCode(1))->toBe(Http2Parser::PROTOCOL_ERROR);

        \Fledge\Async\delay(0.05);
        gc_collect_cycles();
        \Fledge\Async\delay(0.05);

        expect($errors)->toBe([]);
    } finally {
        $peer->close();
    }
})->group('Http2');

it('keeps the deprecated Http2Parser low-value constants as aliases of the driver defaults', function () {
    expect(Http2Parser::LOW_VALUE_FRAME_LIMIT)->toBe(HttpDriver::DEFAULT_LOW_VALUE_FRAME_LIMIT)
        ->and(Http2Parser::LOW_VALUE_FRAME_WINDOW)->toBe(HttpDriver::DEFAULT_LOW_VALUE_FRAME_WINDOW);
})->group('Http2');

it('rejects a non-positive low-value frame limit or window', function (string $class, array $args) {
    $handler = new Fledge\Async\Http\Server\RequestHandler\ClosureRequestHandler(
        fn () => new Fledge\Async\Http\Server\Response(200),
    );
    $common = [$handler, new Fledge\Async\Http\Server\DefaultErrorHandler, new Psr\Log\NullLogger];

    match ($class) {
        'factory' => new Fledge\Async\Http\Server\Driver\DefaultHttpDriverFactory(new Psr\Log\NullLogger, ...$args),
        'parser' => new Http2Parser(
            new Fledge\Async\Http\Server\Driver\Http2Driver(...$common),
            new Fledge\Async\Http\HPack,
            ...$args,
        ),
        default => new $class(...$common, ...$args),
    };
})->with([
    'http2 limit 0' => [Fledge\Async\Http\Server\Driver\Http2Driver::class, ['lowValueFrameLimit' => 0]],
    'http2 window -1' => [Fledge\Async\Http\Server\Driver\Http2Driver::class, ['lowValueFrameWindow' => -1]],
    'http1 limit 0' => [Fledge\Async\Http\Server\Driver\Http1Driver::class, ['lowValueFrameLimit' => 0]],
    'http1 window 0' => [Fledge\Async\Http\Server\Driver\Http1Driver::class, ['lowValueFrameWindow' => 0]],
    'factory limit -5' => ['factory', ['lowValueFrameLimit' => -5]],
    'factory window 0' => ['factory', ['lowValueFrameWindow' => 0]],
    'parser limit 0' => ['parser', ['lowValueFrameLimit' => 0]],
    'parser window 0' => ['parser', ['lowValueFrameWindow' => 0]],
])->throws(ValueError::class)->group('Http2');

it('forwards the low-value frame settings from the factory to the HTTP/1 driver for h2c', function () {
    $factory = new Fledge\Async\Http\Server\Driver\DefaultHttpDriverFactory(
        new Psr\Log\NullLogger,
        allowHttp2Upgrade: true,
        lowValueFrameLimit: 42,
        lowValueFrameWindow: 7,
    );

    $client = new class implements Fledge\Async\Http\Server\Driver\Client
    {
        public function getId(): int
        {
            return 1;
        }

        public function getRemoteAddress(): Fledge\Async\Stream\SocketAddress
        {
            return new Fledge\Async\Stream\InternetAddress('127.0.0.1', 50000);
        }

        public function getLocalAddress(): Fledge\Async\Stream\SocketAddress
        {
            return new Fledge\Async\Stream\InternetAddress('127.0.0.1', 8080);
        }

        public function getTlsInfo(): ?Fledge\Async\Stream\TlsInfo
        {
            return null;
        }

        public function close(): void {}

        public function isClosed(): bool
        {
            return false;
        }

        public function onClose(Closure $onClose): void {}
    };

    $driver = $factory->createHttpDriver(
        new Fledge\Async\Http\Server\RequestHandler\ClosureRequestHandler(fn () => new Fledge\Async\Http\Server\Response(200)),
        new Fledge\Async\Http\Server\DefaultErrorHandler,
        $client,
    );

    expect($driver)->toBeInstanceOf(Fledge\Async\Http\Server\Driver\Http1Driver::class)
        ->and((new ReflectionProperty($driver, 'lowValueFrameLimit'))->getValue($driver))->toBe(42)
        ->and((new ReflectionProperty($driver, 'lowValueFrameWindow'))->getValue($driver))->toBe(7);
})->group('Http2');
