<?php

use Fledge\Async\File\Driver\BlockingFilesystemDriver;
use Fledge\Async\File\Filesystem;
use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\SocketHttpServer;
use Fledge\Async\Http\Server\StaticContent\DocumentRoot;
use Psr\Log\NullLogger;

require_once __DIR__.'/../Fixtures/raw_http1.php';

function rangeRequest(int $port, string $range): string
{
    return rawHttp1Exchange($port, "GET /f.txt HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nRange: bytes={$range}\r\n\r\n");
}

function withRangeServer(Closure $test): void
{
    $dir = sys_get_temp_dir().'/fledge-range-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/f.txt', '0123456789abcdefghij');

    $server = SocketHttpServer::createForDirectAccess(new NullLogger);
    $root = new DocumentRoot($server, new DefaultErrorHandler, $dir, new Filesystem(new BlockingFilesystemDriver));
    // onStart() loads the bundled default mime file (DocumentRoot::DEFAULT_MIME_TYPE_FILE).
    [$server, $port] = startHttp1RawServer($root, $server);

    try {
        $test($port);
    } finally {
        $server->stop();
        @unlink($dir.'/f.txt');
        @rmdir($dir);
    }
}

it('serves a plain range', function () {
    withRangeServer(function (int $port) {
        $response = rangeRequest($port, '2-4');

        expect(rawHttp1Status($response))->toBe(206)->and($response)->toEndWith('234');
    });
});

it('clamps a suffix range larger than the file to the whole file', function () {
    withRangeServer(function (int $port) {
        $response = rangeRequest($port, '-500');

        expect(rawHttp1Status($response))->toBe(206)
            ->and($response)->toMatch('#content-range: bytes 0-19/20#i')
            ->and($response)->toEndWith('0123456789abcdefghij');
    });
});

it('returns exactly the last N bytes for a suffix range', function () {
    withRangeServer(function (int $port) {
        $response = rangeRequest($port, '-3');

        expect($response)->toMatch('#content-range: bytes 17-19/20#i')->and($response)->toEndWith('hij');
    });
});

it('rejects more than ten ranges', function () {
    withRangeServer(function (int $port) {
        $ranges = implode(',', array_map(fn ($i) => "{$i}-{$i}", range(0, 10)));

        expect(rawHttp1Status(rangeRequest($port, $ranges)))->toBe(416);
    });
});

it('rejects overlapping ranges', function () {
    withRangeServer(function (int $port) {
        expect(rawHttp1Status(rangeRequest($port, '0-5,3-8')))->toBe(416);
    });
});

it('accepts disjoint ranges', function () {
    withRangeServer(function (int $port) {
        expect(rawHttp1Status(rangeRequest($port, '0-1,5-6')))->toBe(206);
    });
});

it('serves files with the bundled default mime types', function () {
    expect(is_file(DocumentRoot::DEFAULT_MIME_TYPE_FILE))->toBeTrue();

    withRangeServer(function (int $port) {
        $response = rawHttp1Exchange($port, "GET /f.txt HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

        expect(rawHttp1Status($response))->toBe(200)
            ->and(strtolower($response))->toContain('content-type: text/plain');
    });
});
