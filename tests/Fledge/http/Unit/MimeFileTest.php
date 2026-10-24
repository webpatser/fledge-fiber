<?php

use Fledge\Async\File\Driver\BlockingFilesystemDriver;
use Fledge\Async\File\Filesystem;
use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\SocketHttpServer;
use Fledge\Async\Http\Server\StaticContent\DocumentRoot;
use Psr\Log\NullLogger;

function loadedMimeTypes(): array
{
    $dir = sys_get_temp_dir().'/fledge-mime-'.bin2hex(random_bytes(4));
    mkdir($dir);

    try {
        $root = new DocumentRoot(
            SocketHttpServer::createForDirectAccess(new NullLogger),
            new DefaultErrorHandler,
            $dir,
            new Filesystem(new BlockingFilesystemDriver),
        );
        $root->loadMimeFileTypes(DocumentRoot::DEFAULT_MIME_TYPE_FILE);

        $property = new ReflectionProperty(DocumentRoot::class, 'mimeFileTypes');

        return $property->getValue($root);
    } finally {
        @rmdir($dir);
    }
}

it('round-trips every line of the bundled mime file', function () {
    $types = loadedMimeTypes();
    $lines = array_filter(
        file(DocumentRoot::DEFAULT_MIME_TYPE_FILE, FILE_IGNORE_NEW_LINES),
        fn (string $line) => trim($line) !== '',
    );

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        expect(preg_match('#^(\S+)\s+(\S+)$#', $line, $m))->toBe(1, "Malformed line: {$line}");
        expect($types[strtolower($m[1])] ?? null)->toBe($m[2], "Extension {$m[1]}");
    }

    expect(count($types))->toBe(count($lines));
});

it('parses dotted, plus and dash subtypes in full', function () {
    $types = loadedMimeTypes();

    expect($types['xls'])->toBe('application/vnd.ms-excel')
        ->and($types['svg'])->toBe('image/svg+xml')
        ->and($types['docx'])->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
});

it('serves the modern overrides', function (string $ext, string $type) {
    expect(loadedMimeTypes()[$ext])->toBe($type);
})->with([
    ['js', 'text/javascript'],
    ['mjs', 'text/javascript'],
    ['woff', 'font/woff'],
    ['woff2', 'font/woff2'],
    ['ttf', 'font/ttf'],
    ['otf', 'font/otf'],
    ['xhtml', 'application/xhtml+xml'],
    ['webp', 'image/webp'],
    ['avif', 'image/avif'],
    ['wasm', 'application/wasm'],
    ['jsonld', 'application/ld+json'],
    ['webmanifest', 'application/manifest+json'],
    ['webm', 'video/webm'],
    ['opus', 'audio/ogg'],
    ['flac', 'audio/flac'],
    ['heic', 'image/heic'],
    ['map', 'application/json'],
    ['yaml', 'application/yaml'],
    ['yml', 'application/yaml'],
    ['vtt', 'text/vtt'],
    ['m3u8', 'application/m3u'],
    ['gltf', 'model/gltf+json'],
    ['glb', 'model/gltf-binary'],
]);
