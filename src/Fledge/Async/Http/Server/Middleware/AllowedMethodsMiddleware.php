<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Middleware;

use Fledge\Async\Http\HttpStatus;
use Fledge\Async\Http\Server\ErrorHandler;
use Fledge\Async\Http\Server\Middleware;
use Fledge\Async\Http\Server\Request;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\Response;
use Psr\Log\LoggerInterface as PsrLogger;

final readonly class AllowedMethodsMiddleware implements Middleware
{
    /**
     * HTTP methods that are *known*.
     *
     * Requests for methods not defined here or within Options should result in a 501 (not implemented) response.
     */
    private const KNOWN_METHODS = [
        "GET" => true,
        "HEAD" => true,
        "POST" => true,
        "PUT" => true,
        "PATCH" => true,
        "DELETE" => true,
        "OPTIONS" => true,
        "TRACE" => true,
        "CONNECT" => true,
    ];

    public const DEFAULT_ALLOWED_METHODS = ["GET", "POST", "PUT", "PATCH", "HEAD", "OPTIONS", "DELETE"];

    /**
     * @param array<non-empty-string> $allowedMethods
     */
    public function __construct(
        private ErrorHandler $errorHandler,
        private PsrLogger $logger,
        private array $allowedMethods = self::DEFAULT_ALLOWED_METHODS,
    ) {
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $method = $request->getMethod();

        if (!\in_array($method, $this->allowedMethods, true)) {
            return $this->handleInvalidMethod(
                $request,
                isset(self::KNOWN_METHODS[$method]) ? HttpStatus::METHOD_NOT_ALLOWED : HttpStatus::NOT_IMPLEMENTED,
            );
        }

        if ($method === "OPTIONS" && $request->getUri()->getPath() === "") {
            return $this->handleAsteriskOptionsRequest();
        }

        return $requestHandler->handleRequest($request);
    }

    /**
     * @return list<non-empty-string>
     */
    public function getAllowedMethods(): array
    {
        return \array_values($this->allowedMethods);
    }

    private function handleInvalidMethod(Request $request, int $status): Response
    {
        $client = $request->getClient();

        $local = $client->getLocalAddress()->toString();
        $remote = $client->getRemoteAddress()->toString();
        $method = $request->getMethod();
        $uri = (string) $request->getUri();
        $protocolVersion = $request->getProtocolVersion();

        $context = [
            'method' => $method,
            'uri' => $uri,
            'protocolVersion' => $protocolVersion,
            'local' => $local,
            'remote' => $remote,
        ];

        $this->logger->warning(\sprintf(
            "Invalid request method: %s %s HTTP/%s %s on %s",
            $method,
            $uri,
            $protocolVersion,
            $local,
            $remote,
        ), $context);

        $response = $this->errorHandler->handleError($status);
        $response->setHeader("allow", \implode(", ", $this->allowedMethods));
        return $response;
    }

    private function handleAsteriskOptionsRequest(): Response
    {
        return new Response(HttpStatus::NO_CONTENT, [
            "allow" => \implode(", ", $this->allowedMethods),
        ]);
    }
}
