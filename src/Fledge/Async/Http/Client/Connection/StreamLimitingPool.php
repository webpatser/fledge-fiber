<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Connection;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\Sync\KeyedSemaphore;
use function Fledge\Async\async;

final class StreamLimitingPool implements ConnectionPool
{
    use ForbidCloning;
    use ForbidSerialization;

    public static function byHost(ConnectionPool $delegate, KeyedSemaphore $semaphore): self
    {
        return new self($delegate, $semaphore, static function (Request $request) {
            return $request->getUri()->getHost();
        });
    }

    public static function byStaticKey(
        ConnectionPool $delegate,
        KeyedSemaphore $semaphore,
        string $key = ''
    ): self {
        return new self($delegate, $semaphore, static function () use ($key) {
            return $key;
        });
    }

    public static function byCustomKey(
        ConnectionPool $delegate,
        KeyedSemaphore $semaphore,
        callable $requestToKeyMapper
    ): self {
        return new self($delegate, $semaphore, $requestToKeyMapper);
    }

    private ConnectionPool $delegate;

    private KeyedSemaphore $semaphore;

    /** @var callable */
    private $requestToKeyMapper;

    private function __construct(ConnectionPool $delegate, KeyedSemaphore $semaphore, callable $requestToKeyMapper)
    {
        $this->delegate = $delegate;
        $this->semaphore = $semaphore;
        $this->requestToKeyMapper = $requestToKeyMapper;
    }

    public function getStream(Request $request, Cancellation $cancellation): Stream
    {
        $lock = $this->semaphore->acquire(($this->requestToKeyMapper)($request));

        $stream = $this->delegate->getStream($request, $cancellation);

        return HttpStream::fromStream(
            $stream,
            static function (Request $request, Cancellation $cancellation) use (
                $stream,
                $lock
            ): Response {
                try {
                    $response = $stream->request($request, $cancellation);
                } catch (\Throwable $e) {
                    $lock->release();
                    throw $e;
                }

                // await response being completely received
                async(static function () use ($response, $lock): void {
                    try {
                        $response->getTrailers()->await();
                    } finally {
                        $lock->release();
                    }
                })->ignore();

                return $response;
            },
            static function () use ($lock): void {
                $lock->release();
            }
        );
    }
}
