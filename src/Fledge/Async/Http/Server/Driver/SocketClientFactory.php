<?php declare(strict_types=1);

namespace Fledge\Async\Http\Server\Driver;

use Fledge\Async\CancelledException;
use Fledge\Async\Stream\Socket;
use Fledge\Async\Stream\SocketException;
use Fledge\Async\TimeoutCancellation;
use Psr\Log\LoggerInterface as PsrLogger;
use Time\Duration;

final readonly class SocketClientFactory implements ClientFactory
{
    /**
     * @param Duration|float $tlsHandshakeTimeout Time until the TLS handshake is cancelled, as a `Duration` or in
     *     seconds. Float INF never cancels.
     */
    public function __construct(
        private PsrLogger $logger,
        private Duration|float $tlsHandshakeTimeout = 5,
    ) {
    }

    public function createClient(Socket $socket): ?Client
    {
        $local = $socket->getLocalAddress()->toString();
        $remote = $socket->getRemoteAddress()->toString();

        /** @psalm-suppress RedundantCondition */
        \assert($this->logger->debug(\sprintf("Accepted %s on %s", $remote, $local)) || true);

        if ($socket->isTlsConfigurationAvailable()) {
            try {
                $socket->setupTls(new TimeoutCancellation($this->tlsHandshakeTimeout));

                /** @psalm-suppress RedundantCondition, PossiblyNullReference */
                \assert($this->logger->debug(\sprintf(
                    "TLS negotiated with %s (%s with %s, application protocol: %s)",
                    $remote,
                    $socket->getTlsInfo()->getVersion(),
                    $socket->getTlsInfo()->getCipherName(),
                    $socket->getTlsInfo()->getApplicationLayerProtocol() ?? "none",
                )) || true);
            } catch (SocketException $exception) {
                $message = $exception->getMessage();
                $this->logger->warning(
                    \sprintf("TLS negotiation failed for %s on %s: %s", $remote, $local, $message),
                    ['local' => $local, 'remote' => $remote, 'message' => $message],
                );

                return null;
            } catch (CancelledException) {
                $this->logger->warning(
                    \sprintf("TLS negotiation timed out for %s on %s", $remote, $local),
                    ['local' => $local, 'remote' => $remote],
                );

                return null;
            }
        }

        return new SocketClient($socket);
    }
}
