<?php

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\FormParser\StreamingFormParser;
use Fledge\Async\Http\Server\HttpErrorException;
use Fledge\Async\Http\Server\Request;
use Fledge\Async\Stream\InternetAddress;
use Fledge\Async\Stream\ReadableIterableStream;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\TlsInfo;
use League\Uri\Http;

/** A POST request whose body arrives in exactly the given read chunks. */
function streamingFormRequest(string $contentType, array $chunks): Request
{
    // The parser never touches the client; this stands in for the socket peer only.
    $client = new class implements Client
    {
        public function getId(): int
        {
            return 1;
        }

        public function getRemoteAddress(): SocketAddress
        {
            return new InternetAddress('127.0.0.1', 50000);
        }

        public function getLocalAddress(): SocketAddress
        {
            return new InternetAddress('127.0.0.1', 8080);
        }

        public function getTlsInfo(): ?TlsInfo
        {
            return null;
        }

        public function close(): void {}

        public function isClosed(): bool
        {
            return false;
        }

        public function onClose(\Closure $onClose): void {}
    };

    return new Request(
        $client,
        'POST',
        Http::new('http://localhost/'),
        ['content-type' => $contentType],
        new ReadableIterableStream($chunks),
    );
}

/** @return list<array{string, list<string>}> [field name, non-empty chunks read from the field] in order */
function streamingFormFields(Request $request, ?StreamingFormParser $parser = null): array
{
    $fields = [];

    foreach (($parser ?? new StreamingFormParser)->parseForm($request) as $field) {
        $chunks = [];
        while (null !== $chunk = $field->read()) {
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }
        $fields[] = [$field->getName(), $chunks];
    }

    return $fields;
}

/** @return array<string, string> */
function streamingFormValues(array $fields): array
{
    $values = [];
    foreach ($fields as [$name, $chunks]) {
        $values[$name] = implode('', $chunks);
    }

    return $values;
}

/** @return list<list<string>> the body as one read, as two reads split at every byte position, and as 1-byte reads */
function streamingFormSplits(string $body): array
{
    $splits = [[$body], str_split($body)];
    for ($i = 1; $i < strlen($body); $i++) {
        $splits[] = [substr($body, 0, $i), substr($body, $i)];
    }

    return $splits;
}

it('splits urlencoded keys exactly like parse_str at every read boundary', function (string $body) {
    parse_str($body, $expected);

    foreach (streamingFormSplits($body) as $chunks) {
        $request = streamingFormRequest('application/x-www-form-urlencoded', $chunks);

        expect(streamingFormValues(streamingFormFields($request)))->toBe($expected, json_encode($chunks));
    }
})->with([
    'key without value before a pair' => 'a&b=2',
    'key without value between pairs' => 'a=1&b&c=3',
    'empty segment' => 'a=1&&b=2',
    'lone key' => 'a',
    'lone key with trailing ampersand' => 'a&',
    'leading ampersand' => '&a=1',
    'empty key with value' => '=1',
    'empty key then pair' => '=1&b=2',
    'trailing key named 0' => 'a=1&0',
    'lone key named 0' => '0',
    'escaped ampersand in value' => 'a=c%26d&e',
]);

it('accepts exactly the field count limit for a trailing key without value', function () {
    $request = streamingFormRequest('application/x-www-form-urlencoded', ['a&b']);

    expect(streamingFormValues(streamingFormFields($request, new StreamingFormParser(2))))->toBe(['a' => '', 'b' => '']);
});

it('rejects one field over the field count limit', function () {
    $request = streamingFormRequest('application/x-www-form-urlencoded', ['a&b&c']);

    expect(fn () => streamingFormFields($request, new StreamingFormParser(2)))
        ->toThrow(HttpErrorException::class, 'Maximum number of variables exceeded');
});

/** A one-part multipart body whose header block (from after the boundary line up to the blank line) is $size bytes. */
function streamingMultipartWithHeaderBlock(int $size): string
{
    $prefix = "Content-Disposition: form-data; name=\"a\"\r\nX-Pad: ";

    return "--XB\r\n".$prefix.str_repeat('p', $size - strlen($prefix))."\r\n\r\nvalue\r\n--XB--\r\n";
}

it('accepts a multipart header block exactly at the cap in one read and split at every byte', function () {
    $body = streamingMultipartWithHeaderBlock(1024);

    foreach (streamingFormSplits($body) as $chunks) {
        $request = streamingFormRequest('multipart/form-data; boundary=XB', $chunks);

        expect(streamingFormValues(streamingFormFields($request, new StreamingFormParser(partHeaderSizeLimit: 1024))))
            ->toBe(['a' => 'value'], (string) count($chunks));
    }
});

it('rejects a multipart header block one byte over the cap in one read and split at every byte', function () {
    $body = streamingMultipartWithHeaderBlock(1025);

    foreach (streamingFormSplits($body) as $chunks) {
        $request = streamingFormRequest('multipart/form-data; boundary=XB', $chunks);

        expect(fn () => streamingFormFields($request, new StreamingFormParser(partHeaderSizeLimit: 1024)))
            ->toThrow(HttpErrorException::class, 'Multipart part headers too large');
    }
});

it('parses a multipart body split at every byte position', function () {
    $body = "--XB\r\n"
        ."Content-Disposition: form-data; name=\"a\"\r\n\r\n"
        ."first\r\n--XB\r\n"
        ."Content-Disposition: form-data; name=\"f\"; filename=\"f.txt\"\r\nContent-Type: text/plain\r\n\r\n"
        ."second\r\n--XB--\r\n";

    foreach (streamingFormSplits($body) as $chunks) {
        $request = streamingFormRequest('multipart/form-data; boundary=XB', $chunks);

        expect(streamingFormValues(streamingFormFields($request)))->toBe(['a' => 'first', 'f' => 'second'], json_encode($chunks));
    }
});

it('parses an urlencoded body delivered one byte per read', function () {
    $request = streamingFormRequest('application/x-www-form-urlencoded', str_split('a=b%41c&d=%7e+x%2'));

    expect(streamingFormValues(streamingFormFields($request)))->toBe(['a' => 'bAc', 'd' => '~ x%2']);
});

it('ends an urlencoded value at an ampersand that arrives in a later read', function () {
    $request = streamingFormRequest('application/x-www-form-urlencoded', ['a=1', '&b=2', '&c=%4', '1&d=4']);

    expect(streamingFormValues(streamingFormFields($request)))->toBe(['a' => '1', 'b' => '2', 'c' => 'A', 'd' => '4']);
});

it('keeps streaming an urlencoded value after a percent escape instead of buffering it', function () {
    $request = streamingFormRequest('application/x-www-form-urlencoded', ['a=%41', 'xxxx', 'yyyy', 'zzzz']);

    [[$name, $chunks]] = streamingFormFields($request);

    expect($name)->toBe('a')
        ->and(implode('', $chunks))->toBe('Axxxxyyyyzzzz')
        ->and(count($chunks))->toBeGreaterThan(1);
});

it('rejects a multipart part whose header block exceeds the configured cap', function () {
    $body = "--XB\r\nContent-Disposition: form-data; name=\"a\"\r\nX-Pad: ".str_repeat('p', 4096);
    $request = streamingFormRequest('multipart/form-data; boundary=XB', str_split($body, 100));

    expect(fn () => streamingFormFields($request, new StreamingFormParser(partHeaderSizeLimit: 1024)))
        ->toThrow(HttpErrorException::class, 'Multipart part headers too large');
});

it('rejects a multipart part header block over the default cap', function () {
    $body = "--XB\r\nContent-Disposition: form-data; name=\"a\"\r\nX-Pad: ".str_repeat('p', StreamingFormParser::DEFAULT_PART_HEADER_SIZE_LIMIT + 1);
    $request = streamingFormRequest('multipart/form-data; boundary=XB', str_split($body, 1000));

    expect(fn () => streamingFormFields($request))
        ->toThrow(HttpErrorException::class, 'Multipart part headers too large');
});

it('parses multipart parts whose header terminator is split across reads', function () {
    $body = "--XB\r\n"
        ."Content-Disposition: form-data; name=\"a\"\r\nX-Pad: ".str_repeat('p', 8000)."\r\n\r\n"
        ."first\r\n--XB\r\n"
        ."Content-Disposition: form-data; name=\"f\"; filename=\"f.txt\"\r\nContent-Type: text/plain\r\n\r\n"
        ."second\r\n--XB--\r\n";

    foreach ([1, 7, 1000] as $size) {
        $request = streamingFormRequest('multipart/form-data; boundary=XB', str_split($body, $size));

        expect(streamingFormValues(streamingFormFields($request)))->toBe(['a' => 'first', 'f' => 'second']);
    }
});
