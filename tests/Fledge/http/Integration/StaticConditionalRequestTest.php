<?php

use Fledge\Async\File\Driver\BlockingFilesystemDriver;
use Fledge\Async\File\Filesystem;
use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\SocketHttpServer;
use Fledge\Async\Http\Server\StaticContent\DocumentRoot;
use Psr\Log\NullLogger;

require_once __DIR__.'/../Fixtures/raw_http1.php';

const CONDITIONAL_MTIME = 1_700_000_000;

function conditionalRequest(int $port, string $headers): string
{
    return rawHttp1Exchange($port, "GET /f.txt HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n{$headers}\r\n");
}

function withConditionalServer(Closure $test): void
{
    $dir = sys_get_temp_dir().'/fledge-conditional-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/f.txt', 'hello');
    touch($dir.'/f.txt', CONDITIONAL_MTIME);

    $server = SocketHttpServer::createForDirectAccess(new NullLogger);
    $root = new DocumentRoot($server, new DefaultErrorHandler, $dir, new Filesystem(new BlockingFilesystemDriver));
    [$server, $port] = startHttp1RawServer($root, $server);

    try {
        $test($port);
    } finally {
        $server->stop();
        @unlink($dir.'/f.txt');
        @rmdir($dir);
    }
}

function httpDate(int $timestamp): string
{
    return gmdate('D, d M Y H:i:s', $timestamp).' GMT';
}

it('serves the file when it changed after If-Modified-Since', function () {
    withConditionalServer(function (int $port) {
        $response = conditionalRequest($port, 'If-Modified-Since: '.httpDate(CONDITIONAL_MTIME - 3600)."\r\n");

        expect(rawHttp1Status($response))->toBe(200)->and($response)->toEndWith('hello');
    });
});

it('returns 304 when the file is unchanged since If-Modified-Since', function () {
    withConditionalServer(function (int $port) {
        expect(rawHttp1Status(conditionalRequest($port, 'If-Modified-Since: '.httpDate(CONDITIONAL_MTIME)."\r\n")))->toBe(304)
            ->and(rawHttp1Status(conditionalRequest($port, 'If-Modified-Since: '.httpDate(CONDITIONAL_MTIME + 3600)."\r\n")))->toBe(304);
    });
});

it('ignores If-Modified-Since when a non-matching If-None-Match is present', function () {
    withConditionalServer(function (int $port) {
        $response = conditionalRequest(
            $port,
            "If-None-Match: \"does-not-match\"\r\nIf-Modified-Since: ".httpDate(CONDITIONAL_MTIME + 3600)."\r\n",
        );

        expect(rawHttp1Status($response))->toBe(200)->and($response)->toEndWith('hello');
    });
});
