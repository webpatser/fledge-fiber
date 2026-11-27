<?php

declare(strict_types=1);

use Fledge\Async\Cancellation;
use Fledge\Async\Http\Client\DelegateHttpClient;
use Fledge\Async\Http\Client\Interceptor\SetRequestTimeout;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\NullCancellation;
use Time\Duration;

describe('Request timeouts', function () {
    it('accepts a Duration like the same float', function () {
        $request = new Request('http://localhost/');

        $request->setTcpConnectTimeout(Duration::fromMilliseconds(1500));
        $request->setTlsHandshakeTimeout(Duration::fromSeconds(2));
        $request->setTransferTimeout(Duration::fromMilliseconds(250));
        $request->setInactivityTimeout(Duration::fromSeconds(3));

        expect($request->getTcpConnectTimeout())->toBe(1.5)
            ->and($request->getTlsHandshakeTimeout())->toBe(2.0)
            ->and($request->getTransferTimeout())->toBe(0.25)
            ->and($request->getInactivityTimeout())->toBe(3.0);
    });

    it('clamps a negated Duration to zero like a negative float', function (Duration|float $timeout) {
        $request = new Request('http://localhost/');

        $request->setTcpConnectTimeout($timeout);
        $request->setTlsHandshakeTimeout($timeout);
        $request->setTransferTimeout($timeout);
        $request->setInactivityTimeout($timeout);

        expect($request->getTcpConnectTimeout())->toBe(0.0)
            ->and($request->getTlsHandshakeTimeout())->toBe(0.0)
            ->and($request->getTransferTimeout())->toBe(0.0)
            ->and($request->getInactivityTimeout())->toBe(0.0);
    })->with([
        'negated Duration' => fn () => Duration::fromMilliseconds(10)->negate(),
        'negative float' => -0.01,
    ]);

    it('keeps float seconds unchanged', function () {
        $request = new Request('http://localhost/');

        $request->setTransferTimeout(4.5);

        expect($request->getTransferTimeout())->toBe(4.5);
    });
});

describe('SetRequestTimeout', function () {
    it('applies Duration timeouts to a request', function () {
        $interceptor = new SetRequestTimeout(
            Duration::fromSeconds(1),
            Duration::fromMilliseconds(500),
            Duration::fromSeconds(5),
            Duration::fromMilliseconds(750),
        );

        $client = new class implements DelegateHttpClient {
            public ?Request $seen = null;

            public function request(Request $request, Cancellation $cancellation): Response
            {
                $this->seen = $request;

                throw new RuntimeException('captured');
            }
        };

        expect(fn () => $interceptor->request(new Request('http://localhost/'), new NullCancellation(), $client))
            ->toThrow(RuntimeException::class, 'captured');

        $modified = $client->seen;

        expect($modified->getTcpConnectTimeout())->toBe(1.0)
            ->and($modified->getTlsHandshakeTimeout())->toBe(0.5)
            ->and($modified->getTransferTimeout())->toBe(5.0)
            ->and($modified->getInactivityTimeout())->toBe(0.75);
    });
});
