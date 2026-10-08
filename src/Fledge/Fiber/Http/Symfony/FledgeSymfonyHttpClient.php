<?php

namespace Fledge\Fiber\Http\Symfony;

use Fledge\Async\Cancellation;
use Fledge\Async\Http\Client\BufferedContent;
use Fledge\Async\Http\Client\HttpClient;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;
use Fledge\Fiber\Http\AsyncClientFactory;
use Fledge\Fiber\Http\FledgeGuzzle;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Symfony HttpClient on the Fledge async HTTP client.
 *
 * Lets Symfony consumers (the mailer's HTTP transports: Mailgun, Postmark,
 * Resend, ...) run non-blocking on the Revolt loop. Requests start as soon
 * as request() returns and progress while any fiber awaits, so responses
 * created back to back overlap on the wire; reading a response suspends only
 * the calling fiber.
 *
 * Options are normalized with Symfony's own HttpClientTrait::prepareRequest
 * (json, query, base_uri, auth_basic, auth_bearer, body forms) and then
 * mapped onto the Fledge request:
 *
 *  - timeout: idle timeout, also bounds TCP connect and TLS handshake
 *  - max_duration: total transfer time, 0 for unlimited
 *  - max_connect_duration: tighter bound for TCP connect and TLS handshake
 *  - verify_peer, verify_host, cafile, capath: peer verification;
 *    verify_peer false turns off verification as a whole, verify_host false
 *    on its own is not supported, nor are cafile and capath together
 *  - local_cert, local_pk, passphrase: client certificate
 *  - crypto_method: minimum TLS version
 *  - proxy, no_proxy: http:// proxies (and the proxy env vars Symfony honors),
 *    decided again for every redirect hop
 *  - max_redirects: followed here; on a cross-origin hop only Accept,
 *    Accept-Language, Accept-Encoding, User-Agent and (with the body kept)
 *    Content-Type are forwarded, so no credential header reaches the new host
 *  - http_version: "1.0", "1.1" or "2" (h2 with HTTP/1.1 fallback)
 *
 * bindto, resolve, peer_fingerprint, ciphers, capture_peer_cert_chain and
 * on_progress have no equivalent and throw InvalidArgumentException rather
 * than being ignored silently. Proxy settings the transport cannot use
 * surface as TransportException, as with Symfony's own clients. The body is
 * always buffered in memory.
 */
final class FledgeSymfonyHttpClient implements HttpClientInterface
{
    use HttpClientTrait;

    /** Request headers forwarded when a redirect leaves the original origin. */
    private const CROSS_ORIGIN_HEADERS = ['accept', 'accept-language', 'accept-encoding', 'user-agent'];

    private array $defaultOptions = self::OPTIONS_DEFAULTS;

    private AsyncClientFactory $factory;

    /**
     * @param  array<string, mixed>  $defaultOptions  Symfony request options applied to every request.
     * @param  AsyncClientFactory|null  $factory  Client factory, the shared FledgeGuzzle one by default.
     */
    public function __construct(array $defaultOptions = [], ?AsyncClientFactory $factory = null)
    {
        $this->factory = $factory ?? FledgeGuzzle::factory();

        if ($defaultOptions !== []) {
            [, $this->defaultOptions] = self::prepareRequest(null, null, $defaultOptions, $this->defaultOptions);
        }
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        [$url, $options] = self::prepareRequest($method, $url, $options, $this->defaultOptions);

        self::rejectUnsupported($options);

        $body = $options['body'] ?? '';

        if ($body instanceof \Closure) {
            $body = self::drainBody($body);
        }

        $headers = $options['headers'];

        if (($body !== '' || $method === 'POST' || isset($options['normalized_headers']['content-length']))
            && ! isset($options['normalized_headers']['content-type'])) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        if (! isset($options['normalized_headers']['user-agent'])) {
            $headers[] = 'User-Agent: Symfony HttpClient (Fledge)';
        }

        $request = $this->buildRequest($method, self::urlWithoutFragment($url), $headers, $body, $options);

        $info = [
            'url' => (string) $request->getUri(),
            'original_url' => (string) $request->getUri(),
            'http_method' => $method,
            'user_data' => $options['user_data'],
            'max_duration' => $options['max_duration'],
        ];

        $send = fn (Cancellation $cancellation): array => $this->send($request, $body, $options, $cancellation);

        return new FledgeSymfonyResponse($send, $info);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        return new ResponseStream(FledgeSymfonyResponse::stream($responses, $timeout));
    }

    /**
     * Send the request and follow redirects within max_redirects. The
     * client (TLS and proxy) is resolved per hop and max_duration bounds the
     * whole transfer, redirects included.
     *
     * @return array{Response, array<string, mixed>} the final response and the info it adds
     */
    private function send(Request $request, string $body, array $options, Cancellation $cancellation): array
    {
        $redirects = 0;
        $url = (string) $request->getUri();
        $original = self::parseUrl($url);
        $current = $original;
        $deadline = $options['max_duration'] > 0 ? \microtime(true) + (float) $options['max_duration'] : null;

        while (true) {
            $response = $this->clientFor($options, $current, $request)->request($request, $cancellation);
            $status = $response->getStatus();
            $location = $response->getHeader('location');

            if ($location === null || ! \in_array($status, [301, 302, 303, 307, 308], true)) {
                return [$response, ['url' => $url, 'redirect_count' => $redirects, 'redirect_url' => null]];
            }

            try {
                $target = self::resolveUrl(self::parseUrl($location), self::parseUrl($url));
            } catch (InvalidArgumentException) {
                return [$response, ['url' => $url, 'redirect_count' => $redirects, 'redirect_url' => null]];
            }

            $next = self::urlWithoutFragment($target);

            if ($options['max_redirects'] <= 0 || $redirects >= $options['max_redirects']) {
                return [$response, ['url' => $url, 'redirect_count' => $redirects, 'redirect_url' => $next]];
            }

            // Release the connection before the next hop.
            $response->getBody()->close();

            $method = $request->getMethod();

            if ($status === 303 || (\in_array($status, [301, 302], true) && $method === 'POST')) {
                $method = $method === 'HEAD' ? 'HEAD' : 'GET';
                $body = '';
            }

            $sameOrigin = $target['scheme'] === $original['scheme'] && $target['authority'] === $original['authority'];

            $hop = new Request($next, $method);

            foreach ($request->getHeaderPairs() as [$name, $value]) {
                $lower = \strtolower($name);

                if ($body === '' && \in_array($lower, ['content-type', 'content-length'], true)) {
                    continue;
                }

                if (! $sameOrigin && ! \in_array($lower, self::CROSS_ORIGIN_HEADERS, true)
                    && ! ($lower === 'content-type' && $body !== '')) {
                    continue;
                }

                if ($lower === 'host') {
                    continue;
                }

                $hop->addHeader($name, $value);
            }

            if ($body !== '') {
                $hop->setBody(BufferedContent::fromString($body, $request->getHeader('content-type')));
            }

            self::copyLimits($request, $hop);

            if ($deadline !== null) {
                $remaining = $deadline - \microtime(true);

                if ($remaining <= 0) {
                    throw new TransportException(\sprintf('Max duration was reached for "%s".', $next));
                }

                $hop->setTransferTimeout($remaining);
            }

            $request = $hop;
            $url = $next;
            $current = $target;
            $redirects++;
        }
    }

    /**
     * @param  list<string>  $headers  "Name: value" lines
     */
    private function buildRequest(string $method, string $url, array $headers, string $body, array $options): Request
    {
        $request = new Request($url, $method);
        $request->setBodySizeLimit(0);

        foreach ($headers as $line) {
            [$name, $value] = \explode(': ', $line, 2) + [1 => ''];
            $request->addHeader($name, $value);
        }

        $userInfo = $request->getUri()->getUserInfo();

        if ($userInfo !== '' && ! $request->hasHeader('authorization')) {
            $auth = \array_map('rawurldecode', \explode(':', $userInfo, 2)) + [1 => ''];
            $request->setHeader('Authorization', 'Basic '.\base64_encode(\implode(':', $auth)));
        }

        if ($body !== '') {
            $request->setBody(BufferedContent::fromString($body, $request->getHeader('content-type')));
        }

        if ($options['http_version'] !== null) {
            $request->setProtocolVersions(match ($options['http_version']) {
                '1.0' => ['1.0'],
                '1.1' => ['1.1'],
                default => ['2', '1.1'],
            });
        }

        $timeout = (float) $options['timeout'];
        // max_connect_duration exists from symfony/http-client-contracts 3.7 on.
        $maxConnect = (float) ($options['max_connect_duration'] ?? 0);
        $connect = $maxConnect > 0 ? \min($maxConnect, $timeout) : $timeout;

        $request->setTcpConnectTimeout($connect);
        $request->setTlsHandshakeTimeout($connect);
        $request->setInactivityTimeout($timeout);
        $request->setTransferTimeout($options['max_duration'] > 0 ? (float) $options['max_duration'] : 0);

        return $request;
    }

    private static function copyLimits(Request $from, Request $to): void
    {
        $to->setBodySizeLimit($from->getBodySizeLimit());
        $to->setProtocolVersions($from->getProtocolVersions());
        $to->setTcpConnectTimeout($from->getTcpConnectTimeout());
        $to->setTlsHandshakeTimeout($from->getTlsHandshakeTimeout());
        $to->setInactivityTimeout($from->getInactivityTimeout());
        $to->setTransferTimeout($from->getTransferTimeout());
    }

    /**
     * Resolve the client for one hop. Proxy settings the transport rejects
     * are transport errors, as Symfony reports them.
     *
     * @param  array<string, string|null>  $url  parsed URL parts of the hop
     */
    private function clientFor(array $options, array $url, Request $request): HttpClient
    {
        try {
            return $this->factory->clientFor($this->transportOptions($options, $url), $request->getUri());
        } catch (\InvalidArgumentException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Map the TLS and proxy options onto the factory's Guzzle-style options.
     *
     * @return array<string, mixed>
     */
    private function transportOptions(array $options, array $url): array
    {
        $transport = [];

        if (! $options['verify_peer']) {
            $transport['verify'] = false;
        } elseif (($options['cafile'] ?? null) !== null) {
            $transport['verify'] = (string) $options['cafile'];
        } elseif (($options['capath'] ?? null) !== null) {
            $transport['verify'] = (string) $options['capath'];
        }

        if (($options['local_cert'] ?? null) !== null) {
            $transport['cert'] = [(string) $options['local_cert'], $options['passphrase'] ?? null];

            if (($options['local_pk'] ?? null) !== null) {
                $transport['ssl_key'] = [(string) $options['local_pk'], $options['passphrase'] ?? null];
            }
        }

        // TLS 1.2 is Symfony's default minimum and the Fledge default too;
        // leaving it out keeps such requests on the shared default client.
        if (isset($options['crypto_method']) && $options['crypto_method'] !== \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT) {
            $transport['crypto_method'] = $options['crypto_method'];
        }

        $proxy = self::getProxyUrl($options['proxy'], $url);

        if ($proxy !== null && $proxy !== '') {
            $noProxy = $options['no_proxy'] ?? $_SERVER['no_proxy'] ?? $_SERVER['NO_PROXY'] ?? '';

            $transport['proxy'] = [
                'http' => $proxy,
                'https' => $proxy,
                'no' => $noProxy !== '' ? \preg_split('/[\s,]+/', $noProxy, -1, \PREG_SPLIT_NO_EMPTY) : [],
            ];
        }

        return $transport;
    }

    private static function rejectUnsupported(array $options): void
    {
        $bindto = (string) ($options['bindto'] ?? '0');

        $unsupported = [
            'bindto' => $bindto !== '0' && $bindto !== '',
            'resolve' => ! empty($options['resolve']),
            'peer_fingerprint' => ! empty($options['peer_fingerprint']),
            'ciphers' => ($options['ciphers'] ?? null) !== null,
            'capture_peer_cert_chain' => ! empty($options['capture_peer_cert_chain']),
            'on_progress' => ($options['on_progress'] ?? null) !== null,
        ];

        foreach ($unsupported as $option => $set) {
            if ($set) {
                throw new InvalidArgumentException(\sprintf('Option "%s" is not supported by %s.', $option, self::class));
            }
        }

        if (! $options['verify_peer']) {
            return;
        }

        // Turning off only the host name check would also turn off chain
        // verification here, a silent downgrade.
        if (! $options['verify_host']) {
            throw new InvalidArgumentException(\sprintf('Option "verify_host" set to false with "verify_peer" enabled is not supported by %s; set "verify_peer" to false to turn off verification.', self::class));
        }

        if (($options['cafile'] ?? null) !== null && ($options['capath'] ?? null) !== null) {
            throw new InvalidArgumentException(\sprintf('Options "cafile" and "capath" cannot be combined with %s; pass one of them.', self::class));
        }
    }

    /**
     * Read a streaming body (resource, iterable or closure) into a string.
     */
    private static function drainBody(\Closure $body): string
    {
        $content = '';

        while ('' !== $chunk = $body(16372)) {
            if (! \is_string($chunk)) {
                throw new \TypeError(\sprintf('The return value of the "body" option callback must be a string, "%s" returned.', \get_debug_type($chunk)));
            }

            $content .= $chunk;
        }

        return $content;
    }

    /**
     * @param  array<string, string|null>  $url  parsed URL parts from prepareRequest
     */
    private static function urlWithoutFragment(array $url): string
    {
        unset($url['fragment']);

        return \implode('', $url);
    }
}
