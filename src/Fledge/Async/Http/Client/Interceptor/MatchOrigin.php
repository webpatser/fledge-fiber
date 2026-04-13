<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Interceptor;

use Fledge\Async\Cancellation;
use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;
use Fledge\Async\Http\Client\ApplicationInterceptor;
use Fledge\Async\Http\Client\DelegateHttpClient;
use Fledge\Async\Http\Client\HttpException;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;
use League\Uri\Http;
use Psr\Http\Message\UriInterface;

final readonly class MatchOrigin implements ApplicationInterceptor
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var ApplicationInterceptor[] */
    private array $originMap;

    /**
     * @param ApplicationInterceptor[] $originMap
     *
     * @throws HttpException
     */
    public function __construct(array $originMap, private ?ApplicationInterceptor $default = null)
    {
        $validatedMap = [];
        foreach ($originMap as $origin => $interceptor) {
            if (!$interceptor instanceof ApplicationInterceptor) {
                $type = \get_debug_type($interceptor);
                throw new HttpException('Origin map must be a map from origin to ApplicationInterceptor, got ' . $type);
            }

            $validatedMap[$this->checkOrigin($origin)] = $interceptor;
        }

        $this->originMap = $validatedMap;
    }

    public function request(
        Request $request,
        Cancellation $cancellation,
        DelegateHttpClient $httpClient
    ): Response {
        $interceptor = $this->originMap[$this->normalizeOrigin($request->getUri())] ?? $this->default;

        if (!$interceptor) {
            return $httpClient->request($request, $cancellation);
        }

        return $interceptor->request($request, $cancellation, $httpClient);
    }

    /**
     * @throws HttpException
     */
    private function checkOrigin(string $origin): string
    {
        try {
            $originUri = Http::new($origin);
        } catch (\Exception) {
            throw new HttpException("Invalid origin provided: parsing failed: " . $origin);
        }

        if (!\in_array($originUri->getScheme(), ['http', 'https'], true)) {
            throw new HttpException('Invalid origin with unsupported scheme: ' . $origin);
        }

        if ($originUri->getHost() === '') {
            throw new HttpException('Invalid origin without host: ' . $origin);
        }

        if ($originUri->getUserInfo() !== '') {
            throw new HttpException('Invalid origin with user info, which must not be present: ' . $origin);
        }

        if (!\in_array($originUri->getPath(), ['', '/'], true)) {
            throw new HttpException('Invalid origin with path, which must not be present: ' . $origin);
        }

        if ($originUri->getQuery() !== '') {
            throw new HttpException('Invalid origin with query, which must not be present: ' . $origin);
        }

        if ($originUri->getFragment() !== '') {
            throw new HttpException('Invalid origin with fragment, which must not be present: ' . $origin);
        }

        return $this->normalizeOrigin($originUri);
    }

    private function normalizeOrigin(UriInterface $uri): string
    {
        $defaultPort = $uri->getScheme() === 'https' ? 443 : 80;

        return $uri->getScheme() . '://' . $uri->getHost() . ':' . ($uri->getPort() ?? $defaultPort);
    }
}
