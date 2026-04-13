<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Middleware;

use Fledge\Async\DeferredFuture;
use Fledge\Async\Http\Server\Middleware;
use Fledge\Async\Http\Server\Request;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\Response;

final class ConcurrencyLimitingMiddleware implements Middleware
{
    private int $pendingRequests = 0;

    /** @var \SplQueue<DeferredFuture> */
    private readonly \SplQueue $queue;

    /**
     * @param positive-int $concurrencyLimit
     */
    public function __construct(private readonly int $concurrencyLimit)
    {
        /** @psalm-suppress DocblockTypeContradiction */
        if ($this->concurrencyLimit <= 0) {
            throw new \ValueError('The concurrency limit must be a positive integer');
        }

        $this->queue = new \SplQueue();
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        if (++$this->pendingRequests > $this->concurrencyLimit) {
            $deferred = new DeferredFuture();
            $this->queue->push($deferred);
            $deferred->getFuture()->await();
        }

        try {
            return $requestHandler->handleRequest($request);
        } finally {
            --$this->pendingRequests;
            if (!$this->queue->isEmpty()) {
                $this->queue->shift()->complete();
            }
        }
    }
}
