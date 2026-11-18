<?php

declare(strict_types=1);

use Fledge\Async\CancelledException;
use Fledge\Async\DeferredCancellation;
use Fledge\Async\Dns\DnsConfig;
use Fledge\Async\Dns\DnsException;
use Fledge\Async\Dns\DnsRecord;
use Fledge\Async\Dns\Rfc1035StubDnsResolver;
use Fledge\Async\Dns\StaticDnsConfigLoader;
use Revolt\EventLoop;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/**
 * Local UDP nameserver on 127.0.0.1 so no real DNS server is queried.
 * $state->mode is 'answer' or 'servfail', $state->delay delays each reply.
 */
function startFakeNameserver(object $state): array
{
    $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if ($server === false) {
        throw new RuntimeException("Could not bind UDP socket: {$errstr}");
    }

    stream_set_blocking($server, false);
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    $callbackId = EventLoop::onReadable($server, static function (string $id, $server) use ($state): void {
        $query = stream_socket_recvfrom($server, 512, 0, $peer);
        if ($query === false || strlen($query) < 12) {
            return;
        }

        $state->queries++;

        // Question section: labels up to the zero byte, then QTYPE and QCLASS.
        $offset = 12;
        while (($length = ord($query[$offset])) !== 0) {
            $offset += $length + 1;
        }
        $question = substr($query, 12, $offset + 5 - 12);

        $answer = $state->mode === 'answer';
        $response = substr($query, 0, 2)
            . ($answer ? "\x81\x80" : "\x81\x82")
            . "\x00\x01" . ($answer ? "\x00\x01" : "\x00\x00") . "\x00\x00\x00\x00"
            . $question;

        if ($answer) {
            $response .= "\xC0\x0C\x00\x01\x00\x01" . pack('N', 60) . "\x00\x04" . inet_pton('192.0.2.1');
        }

        $send = static function () use ($server, $response, $peer): void {
            @stream_socket_sendto($server, $response, 0, $peer);
        };

        if ($state->delay > 0) {
            EventLoop::delay($state->delay, $send);
        } else {
            $send();
        }
    });

    return [$server, $callbackId, $port];
}

beforeEach(function () {
    $this->ns = (object) ['mode' => 'answer', 'delay' => 0.0, 'queries' => 0];
    [$this->nsSocket, $this->nsCallback, $port] = startFakeNameserver($this->ns);

    $config = (new DnsConfig(["127.0.0.1:{$port}"]))->withTimeout(2)->withAttempts(1);
    $this->resolver = new Rfc1035StubDnsResolver(null, new StaticDnsConfigLoader($config));
});

afterEach(function () {
    EventLoop::cancel($this->nsCallback);
    fclose($this->nsSocket);
});

it('does not pin a settled query when the name normalizes differently', function () {
    $this->ns->mode = 'servfail';

    expect(fn () => $this->resolver->query('fledge.test.', DnsRecord::A))
        ->toThrow(DnsException::class);

    // A failed answer is not cached, so the next query must reach the server again
    // instead of returning the stale failed future from the pending map.
    $this->ns->mode = 'answer';

    $records = $this->resolver->query('fledge.test.', DnsRecord::A);

    expect($records)->toHaveCount(1)
        ->and($records[0]->getValue())->toBe('192.0.2.1')
        ->and($this->ns->queries)->toBe(2);
});

it('does not fail coalesced callers when the first caller cancels', function () {
    $this->ns->delay = 0.2;

    $cancellation = new DeferredCancellation;

    $first = async(fn () => $this->resolver->query('fledge.test', DnsRecord::A, $cancellation->getCancellation()));
    $second = async(fn () => $this->resolver->query('fledge.test', DnsRecord::A));

    delay(0.05);
    $cancellation->cancel();

    expect(fn () => $first->await())->toThrow(CancelledException::class);

    $records = $second->await();

    expect($records)->toHaveCount(1)
        ->and($records[0]->getValue())->toBe('192.0.2.1')
        ->and($this->ns->queries)->toBe(1);
});
