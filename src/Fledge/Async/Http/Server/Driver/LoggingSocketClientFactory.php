<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Driver;

use Fledge\Async\Stream\Socket;
use Psr\Log\LoggerInterface as PsrLogger;

final readonly class LoggingSocketClientFactory implements ClientFactory
{
    public function __construct(
        private ClientFactory $clientFactory,
        private PsrLogger $logger,
    ) {
    }

    public function createClient(Socket $socket): ?Client
    {
        $local = $socket->getLocalAddress()->toString();
        $remote = $socket->getRemoteAddress()->toString();
        $context = [
            'local' => $local,
            'remote' => $remote,
        ];

        $this->logger->info(\sprintf("Accepted %s on %s", $local, $remote), $context);

        $client = $this->clientFactory->createClient($socket);

        if ($client === null) {
            $this->logger->notice(
                \sprintf("Creation of client for %s on %s failed", $local, $remote),
                $context,
            );
        }

        return $client;
    }
}
