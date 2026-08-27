<?php

use Fledge\Async\Stream\Payload;
use Fledge\Async\Stream\ReadableBuffer;
use Fledge\Async\Stream\ReadableIterableStream;
use Fledge\Fiber\Http\AsyncBodyStream;

function chunkedPayload(string ...$chunks): Payload
{
    return new Payload(new ReadableIterableStream($chunks));
}

it('reads across payload chunks with leftover buffering', function () {
    $stream = new AsyncBodyStream(chunkedPayload('hello ', 'world'));

    expect($stream->read(3))->toBe('hel')
        ->and($stream->read(3))->toBe('lo ')
        ->and($stream->read(100))->toBe('world')
        ->and($stream->read(10))->toBe('')
        ->and($stream->eof())->toBeTrue();
});

it('tracks the read offset', function () {
    $stream = new AsyncBodyStream(chunkedPayload('abcdef'));

    $stream->read(2);
    $stream->read(2);

    expect($stream->tell())->toBe(4);
});

it('exposes the content length as its size', function () {
    expect((new AsyncBodyStream(chunkedPayload('abc'), 3))->getSize())->toBe(3)
        ->and((new AsyncBodyStream(chunkedPayload('abc')))->getSize())->toBeNull();
});

it('is not seekable and not writable', function () {
    $stream = new AsyncBodyStream(new Payload(new ReadableBuffer('abc')));

    expect($stream->isSeekable())->toBeFalse()
        ->and($stream->isWritable())->toBeFalse()
        ->and($stream->isReadable())->toBeTrue()
        ->and(fn () => $stream->seek(0))->toThrow(RuntimeException::class)
        ->and(fn () => $stream->rewind())->toThrow(RuntimeException::class)
        ->and(fn () => $stream->write('x'))->toThrow(RuntimeException::class);
});

it('returns the remaining contents', function () {
    $stream = new AsyncBodyStream(chunkedPayload('hello ', 'world'));

    $stream->read(2);

    expect($stream->getContents())->toBe('llo world')
        ->and($stream->eof())->toBeTrue();
});

it('closes the payload when closed', function () {
    $payload = new Payload(new ReadableIterableStream(['abc']));
    $stream = new AsyncBodyStream($payload);

    $stream->close();

    expect($payload->isClosed())->toBeTrue()
        ->and($stream->isReadable())->toBeFalse()
        ->and(fn () => $stream->read(1))->toThrow(RuntimeException::class);
});

it('reports eof only after the payload is drained', function () {
    $stream = new AsyncBodyStream(new Payload(new ReadableBuffer('abc')));

    expect($stream->eof())->toBeFalse();

    $stream->getContents();

    expect($stream->eof())->toBeTrue();
});
