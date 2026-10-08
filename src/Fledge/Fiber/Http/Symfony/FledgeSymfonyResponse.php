<?php

namespace Fledge\Fiber\Http\Symfony;

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredCancellation;
use Fledge\Async\Future;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\Http\Client\TimeoutException as AsyncHttpTimeoutException;
use Fledge\Async\TimeoutCancellation;
use Fledge\Async\TimeoutException as AsyncTimeoutException;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function Fledge\Async\async;
use function Fledge\Async\Future\awaitFirst;

/**
 * Lazy Symfony response over a Fledge async request.
 *
 * The request is already running on the Revolt loop; every accessor only
 * awaits what it needs (headers, then body chunks), suspending the calling
 * fiber rather than the process. Error semantics follow Symfony's own
 * responses: getHeaders(), getContent() and toArray() throw for 3xx, 4xx
 * and 5xx unless $throw is false, transport failures throw a
 * TransportExceptionInterface, and a response destroyed before anything
 * awaited its headers waits for them and throws on an error status.
 */
final class FledgeSymfonyResponse implements ResponseInterface
{
    private DeferredCancellation $cancellation;

    /** @var Future<array{Response, array<string, mixed>}> */
    private Future $future;

    private ?Response $response = null;

    /** @var Future<string|null>|null */
    private ?Future $pendingRead = null;

    private ?TransportExceptionInterface $error = null;

    private bool $initialized = false;

    private bool $complete = false;

    private bool $firstYielded = false;

    private bool $lastYielded = false;

    private bool $didTimeout = false;

    private string $content = '';

    private ?array $jsonData = null;

    /** @var array<string, list<string>> */
    private array $headers = [];

    /** @var array<string, mixed> */
    private array $info;

    /**
     * @param  \Closure(Cancellation): array{Response, array<string, mixed>}  $send
     * @param  array<string, mixed>  $info
     *
     * @internal Created by FledgeSymfonyHttpClient.
     */
    public function __construct(\Closure $send, array $info)
    {
        $this->info = $info + [
            'http_code' => 0,
            'response_headers' => [],
            'error' => null,
            'canceled' => false,
            'redirect_count' => 0,
            'redirect_url' => null,
            'start_time' => \microtime(true),
            'total_time' => 0.0,
            'size_download' => 0.0,
            'download_content_length' => -1.0,
            'http_version' => null,
            'debug' => '',
        ];

        $this->cancellation = new DeferredCancellation;
        $cancellation = $this->cancellation->getCancellation();

        // The closure holds no reference to $this, so dropping the response
        // destructs it (and cancels the request) as Symfony's do.
        $this->future = async(static fn (): array => $send($cancellation))->ignore();
    }

    public function getStatusCode(): int
    {
        $this->initialize();

        return $this->info['http_code'];
    }

    public function getHeaders(bool $throw = true): array
    {
        $this->initialize();

        if ($throw) {
            $this->checkStatusCode();
        }

        return $this->headers;
    }

    public function getContent(bool $throw = true): string
    {
        $this->initialize();

        if ($throw) {
            $this->checkStatusCode();
        }

        while ($this->readChunk() !== null) {
            // Chunks accumulate in $this->content.
        }

        return $this->content;
    }

    public function toArray(bool $throw = true): array
    {
        if ('' === $content = $this->getContent($throw)) {
            throw new JsonException('Response body is empty.');
        }

        if ($this->jsonData !== null) {
            return $this->jsonData;
        }

        try {
            $content = \json_decode($content, true, 512, \JSON_BIGINT_AS_STRING | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new JsonException($e->getMessage().\sprintf(' for "%s".', $this->getInfo('url')), $e->getCode());
        }

        if (! \is_array($content)) {
            throw new JsonException(\sprintf('JSON content was expected to decode to an array, "%s" returned for "%s".', \get_debug_type($content), $this->getInfo('url')));
        }

        return $this->jsonData = $content;
    }

    public function cancel(): void
    {
        $this->info['canceled'] = true;
        $this->info['error'] = 'Response has been canceled.';

        if (! $this->complete) {
            $this->error ??= new TransportException('Response has been canceled.');
        }

        $this->close();
    }

    public function getInfo(?string $type = null): mixed
    {
        if (! $this->complete && $this->error === null) {
            $this->info['total_time'] = \microtime(true) - $this->info['start_time'];
        }

        return $type !== null ? $this->info[$type] ?? null : $this->info;
    }

    public function __destruct()
    {
        try {
            if (! $this->initialized && $this->info['error'] === null && ! $this->didTimeout) {
                $this->initialize();
                $this->checkStatusCode();
            }
        } finally {
            $this->close();
        }
    }

    /**
     * Yield Symfony chunks for the given responses: a first chunk once
     * headers arrive, data chunks as the body streams, a last chunk at the
     * end. Responses are served in the order they make progress. With a
     * timeout, once none of them made progress for that long, every pending
     * response yields a timeout chunk and the wait starts over, as Symfony's
     * own stream() does. After a first chunk the status code is checked
     * unless the consumer already did, so error statuses throw.
     *
     * @param  iterable<ResponseInterface>  $responses
     * @return \Generator<FledgeSymfonyResponse, ChunkInterface>
     *
     * @internal Use FledgeSymfonyHttpClient::stream().
     */
    public static function stream(iterable $responses, ?float $timeout = null): \Generator
    {
        $pending = [];

        foreach ($responses as $response) {
            if (! $response instanceof self) {
                throw new \TypeError(\sprintf('"%s::stream()" expects parameter 1 to be an iterable of %s objects, "%s" given.', FledgeSymfonyHttpClient::class, self::class, \get_debug_type($response)));
            }

            $pending[\spl_object_id($response)] = $response;
        }

        $lastActivity = \microtime(true);

        while ($pending !== []) {
            $waiting = [];
            $progressed = false;

            foreach ($pending as $id => $response) {
                $future = $response->awaiting();

                if ($future !== null) {
                    $waiting[$id] = $future;

                    continue;
                }

                $progressed = true;
                $chunk = $response->nextChunk();

                if ($chunk === null || $chunk instanceof LastChunk || $chunk instanceof ErrorChunk) {
                    unset($pending[$id]);
                }

                if ($chunk === null) {
                    continue;
                }

                yield $response => $chunk;

                if ($chunk instanceof FirstChunk && ! $response->initialized && $response->info['error'] === null) {
                    // Ensure the HTTP status code is always checked.
                    $response->getHeaders(true);
                }
            }

            if ($progressed) {
                // Serve the responses that moved again before waiting.
                $lastActivity = \microtime(true);

                continue;
            }

            $remaining = $timeout === null ? null : \max(0.0, $timeout) - (\microtime(true) - $lastActivity);

            if ($remaining !== null && $remaining <= 0) {
                foreach ($pending as $response) {
                    $response->didTimeout = true;

                    yield $response => new ErrorChunk((int) $response->info['size_download'], \sprintf('Idle timeout reached for "%s".', $response->info['url']));
                }

                $lastActivity = \microtime(true);

                continue;
            }

            try {
                awaitFirst($waiting, $remaining !== null ? new TimeoutCancellation($remaining) : null);
            } catch (\Throwable) {
                // Failures surface through nextChunk(); a timeout loops back.
            }
        }
    }

    /**
     * The future this response waits on before its next chunk is ready, or
     * null when nextChunk() can answer without suspending.
     *
     * @return Future<mixed>|null
     */
    private function awaiting(): ?Future
    {
        if ($this->lastYielded || $this->error !== null) {
            return null;
        }

        if ($this->response === null) {
            return $this->future->isComplete() ? null : $this->future;
        }

        if (! $this->firstYielded || $this->complete) {
            return null;
        }

        $payload = $this->response->getBody();
        $this->pendingRead ??= async(static fn (): ?string => $payload->read())->ignore();

        return $this->pendingRead->isComplete() ? null : $this->pendingRead;
    }

    /**
     * Advance this response by one chunk for stream(). Called only once
     * awaiting() reports the chunk ready, so it does not suspend.
     */
    private function nextChunk(): ?ChunkInterface
    {
        if ($this->lastYielded) {
            return null;
        }

        if ($this->error !== null) {
            $this->lastYielded = true;

            return new ErrorChunk((int) $this->info['size_download'], $this->error);
        }

        try {
            if (! $this->firstYielded) {
                $this->receiveHeaders();
                $this->firstYielded = true;

                return new FirstChunk;
            }

            $offset = (int) $this->info['size_download'];
            $data = $this->readChunk();

            if ($data === null) {
                $this->lastYielded = true;

                return new LastChunk($offset);
            }

            return new DataChunk($offset, $data);
        } catch (TransportExceptionInterface) {
            $this->lastYielded = true;

            return new ErrorChunk((int) $this->info['size_download'], $this->error);
        }
    }

    private function initialize(): void
    {
        if ($this->error !== null && ! $this->initialized) {
            $this->initialized = true;

            throw $this->error;
        }

        $this->initialized = true;
        $this->receiveHeaders();
    }

    /**
     * Await the response headers.
     */
    private function receiveHeaders(): void
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        if ($this->response !== null) {
            return;
        }

        try {
            [$response, $meta] = $this->future->await();
        } catch (\Throwable $e) {
            $this->fail($e);
        }

        $this->response = $response;
        $this->headers = $response->getHeaders();
        $this->info = $meta + $this->info;
        $this->info['http_code'] = $response->getStatus();
        $this->info['http_version'] = $response->getProtocolVersion();

        $lines = [\sprintf('HTTP/%s %d %s', $response->getProtocolVersion(), $response->getStatus(), $response->getReason())];

        foreach ($response->getHeaderPairs() as [$name, $value]) {
            $lines[] = $name.': '.$value;
        }

        $this->info['response_headers'] = $lines;

        $length = $response->getHeader('content-length');

        if (\is_numeric($length)) {
            $this->info['download_content_length'] = (float) $length;
        }

        if ($response->getRequest()->getMethod() === 'HEAD' || \in_array($response->getStatus(), [204, 304], true)) {
            $this->finish();
        }
    }

    /**
     * Read the next body chunk, or null at the end of the body.
     */
    private function readChunk(): ?string
    {
        $this->receiveHeaders();

        if ($this->complete) {
            return null;
        }

        $payload = $this->response->getBody();
        $this->pendingRead ??= async(static fn (): ?string => $payload->read())->ignore();

        try {
            $chunk = $this->pendingRead->await();
        } catch (\Throwable $e) {
            $this->pendingRead = null;
            $this->fail($e);
        }

        $this->pendingRead = null;

        if ($chunk === null) {
            $this->finish();

            return null;
        }

        $this->content .= $chunk;
        $this->info['size_download'] += \strlen($chunk);

        return $chunk;
    }

    private function finish(): void
    {
        $this->complete = true;
        $this->info['total_time'] = \microtime(true) - $this->info['start_time'];

        if ($this->info['download_content_length'] < 0) {
            $this->info['download_content_length'] = $this->info['size_download'];
        }
    }

    /**
     * Record a transport failure and throw it as a Symfony exception.
     */
    private function fail(\Throwable $e): never
    {
        if ($this->error === null) {
            $message = $this->info['canceled'] ? 'Response has been canceled.' : $e->getMessage();

            $this->error = self::isTimeout($e)
                ? new TimeoutException($message, 0, $e)
                : new TransportException($message, 0, $e);

            $this->info['error'] = $message;
            $this->info['total_time'] = \microtime(true) - $this->info['start_time'];
        }

        $this->close();

        throw $this->error;
    }

    private static function isTimeout(\Throwable $e): bool
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof AsyncHttpTimeoutException || $e instanceof AsyncTimeoutException) {
                return true;
            }
        }

        return false;
    }

    private function checkStatusCode(): void
    {
        $code = $this->info['http_code'];

        if ($code < 300) {
            return;
        }

        $errorBody = function (): string {
            try {
                return \substr($this->getContent(false), 0, 32768);
            } catch (TransportExceptionInterface) {
                return \substr($this->content, 0, 32768);
            }
        };

        if ($code >= 500) {
            throw new ServerException($this, $errorBody);
        }

        if ($code >= 400) {
            throw new ClientException($this, $errorBody);
        }

        throw new RedirectionException($this, $errorBody);
    }

    private function close(): void
    {
        if (! $this->complete) {
            $this->cancellation->cancel();
            $this->response?->getBody()->close();
        }
    }
}
