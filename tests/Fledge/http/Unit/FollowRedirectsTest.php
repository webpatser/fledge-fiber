<?php

use Fledge\Async\Cancellation;
use Fledge\Async\Http\Client\DelegateHttpClient;
use Fledge\Async\Http\Client\Interceptor\FollowRedirects;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\NullCancellation;

/**
 * A delegate that answers the first request with a redirect and records every request it receives.
 */
function redirectingDelegate(string $location): object
{
    return new class($location) implements DelegateHttpClient
    {
        /** @var list<Request> */
        public array $requests = [];

        public function __construct(private string $location) {}

        public function request(Request $request, Cancellation $cancellation): Response
        {
            $this->requests[] = clone $request;

            if (count($this->requests) === 1) {
                return new Response('1.1', 302, null, ['location' => $this->location], '', $request);
            }

            return new Response('1.1', 200, null, [], 'done', $request);
        }
    };
}

it('strips credentials when a redirect downgrades https to http on the same host', function () {
    $delegate = redirectingDelegate('http://example.com/next');

    $request = new Request('https://example.com/start');
    $request->setHeader('Authorization', 'Bearer secret');
    $request->setHeader('Cookie', 'session=abc');

    (new FollowRedirects(5))->request($request, new NullCancellation, $delegate);

    expect($delegate->requests)->toHaveCount(2)
        ->and($delegate->requests[1]->hasHeader('authorization'))->toBeFalse()
        ->and($delegate->requests[1]->hasHeader('cookie'))->toBeFalse();
});

it('keeps credentials on a same-origin redirect', function () {
    $delegate = redirectingDelegate('/next');

    $request = new Request('https://example.com/start');
    $request->setHeader('Authorization', 'Bearer secret');

    (new FollowRedirects(5))->request($request, new NullCancellation, $delegate);

    expect($delegate->requests)->toHaveCount(2)
        ->and($delegate->requests[1]->getHeader('authorization'))->toBe('Bearer secret');
});
