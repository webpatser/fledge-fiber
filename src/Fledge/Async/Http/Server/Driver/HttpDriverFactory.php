<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Driver;

use Fledge\Async\Http\Server\ErrorHandler;
use Fledge\Async\Http\Server\RequestHandler;

interface HttpDriverFactory
{
    public function createHttpDriver(
        RequestHandler $requestHandler,
        ErrorHandler $errorHandler,
        Client $client,
    ): HttpDriver;

    /**
     * @return list<string>
     */
    public function getApplicationLayerProtocols(): array;
}
