<?php

use Fledge\Async\Http\Server\Request as ServerRequest;
use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Async\Http\Server\SocketHttpServer;
use Fledge\Fiber\Http\Symfony\FledgeSymfonyHttpClient;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mime\Email;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Future\await;

require_once __DIR__.'/../Fixtures/loopback.php';

/**
 * TLS loopback standing in for api.postmarkapp.com (the transport hard-codes https): records the request and
 * answers the /email contract after a short delay so overlap is measurable.
 *
 * @return array{SocketHttpServer, int, ArrayObject}
 */
function startPostmarkLoopback(float $delay = 0.0): array
{
    $seen = new ArrayObject;

    [$server, $port] = startLoopbackServer(makeSelfSignedCertificate(), function (ServerRequest $request) use ($seen, $delay): ServerResponse {
        $id = count($seen) + 1;
        $seen[] = [
            'path' => $request->getUri()->getPath(),
            'token' => $request->getHeader('x-postmark-server-token'),
            'body' => json_decode($request->getBody()->buffer(), true),
        ];

        if ($delay > 0) {
            delay($delay);
        }

        return new ServerResponse(200, ['content-type' => 'application/json'], json_encode([
            'To' => 'to@example.test',
            'MessageID' => 'abc-'.$id,
            'ErrorCode' => 0,
            'Message' => 'OK',
        ]));
    });

    return [$server, $port, $seen];
}

function postmarkTransport(int $port): PostmarkApiTransport
{
    $transport = new PostmarkApiTransport(
        'server-token-123',
        new FledgeSymfonyHttpClient(['verify_peer' => false, 'verify_host' => false, 'timeout' => LOOPBACK_TIMEOUT, 'max_duration' => LOOPBACK_TIMEOUT]),
    );
    $transport->setHost('127.0.0.1');
    $transport->setPort($port);

    return $transport;
}

function postmarkEmail(string $subject = 'Hello'): Email
{
    return (new Email)
        ->from('from@example.test')
        ->to('to@example.test')
        ->subject($subject)
        ->text('Plain body');
}

it('sends through the Symfony Postmark API transport with JSON body and server token', function () {
    [$server, $port, $seen] = startPostmarkLoopback();

    try {
        $transport = postmarkTransport($port);
        $sent = $transport->send(postmarkEmail());

        expect($sent->getMessageId())->toBe('abc-1')
            ->and($seen)->toHaveCount(1)
            ->and($seen[0]['path'])->toBe('/email')
            ->and($seen[0]['token'])->toBe('server-token-123')
            ->and($seen[0]['body']['Subject'])->toBe('Hello')
            ->and($seen[0]['body']['TextBody'])->toBe('Plain body')
            ->and($seen[0]['body']['From'])->toBe('from@example.test')
            ->and($seen[0]['body']['To'])->toBe('to@example.test');
    } finally {
        $server->stop();
    }
});

it('overlaps two concurrent sends in fibers', function () {
    [$server, $port, $seen] = startPostmarkLoopback(0.3);

    try {
        $transport = postmarkTransport($port);

        $start = microtime(true);
        $a = async(fn () => $transport->send(postmarkEmail('one')));
        $b = async(fn () => $transport->send(postmarkEmail('two')));
        [$sentA, $sentB] = await([$a, $b]);
        $elapsed = microtime(true) - $start;

        expect($seen)->toHaveCount(2)
            ->and($sentA->getMessageId())->not->toBe($sentB->getMessageId())
            ->and($elapsed)->toBeLessThan(0.55);
    } finally {
        $server->stop();
    }
});
