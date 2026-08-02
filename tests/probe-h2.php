<?php

/**
 * Temporary CI probe for the HTTP/2 loopback hang.
 *
 * The loopback test passes locally in milliseconds but stalls on the GitHub
 * runner before Pest can print a result, so the failure carries no indication
 * of which stage blocked. This walks the same steps and flushes a marker
 * before each, so the last marker printed names the stage that hung.
 *
 * Delete once the hang is understood.
 */

require __DIR__.'/../vendor/autoload.php';

use Fledge\Async\Http\Client\Connection\DefaultConnectionFactory;
use Fledge\Async\Http\Client\Connection\UnlimitedConnectionPool;
use Fledge\Async\Http\Client\HttpClientBuilder;
use Fledge\Async\Http\Client\Request as ClientRequest;
use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\RequestHandler\ClosureRequestHandler;
use Fledge\Async\Http\Server\Response as ServerResponse;
use Fledge\Async\Http\Server\SocketHttpServer;
use Fledge\Async\Stream\BindContext;
use Fledge\Async\Stream\Certificate;
use Fledge\Async\Stream\ClientTlsContext;
use Fledge\Async\Stream\ConnectContext;
use Fledge\Async\Stream\ServerTlsContext;
use Psr\Log\NullLogger;

function mark(string $step): void
{
    fwrite(STDERR, '[probe] '.$step.PHP_EOL);
    fflush(STDERR);
}

mark('start');

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
mark('pkey_new: '.var_export($key !== false, true));

$csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
mark('csr_new: '.var_export($csr !== false, true));

$cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
mark('csr_sign: '.var_export($cert !== false, true));

openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem);
$certPath = tempnam(sys_get_temp_dir(), 'probe-cert-');
$keyPath = tempnam(sys_get_temp_dir(), 'probe-key-');
file_put_contents($certPath, $certPem);
file_put_contents($keyPath, $keyPem);
mark('cert written: '.strlen((string) $certPem).' bytes cert, '.strlen((string) $keyPem).' bytes key');

$server = SocketHttpServer::createForDirectAccess(new NullLogger);
mark('server created');

$server->expose('127.0.0.1:0', (new BindContext)->withTlsContext(
    (new ServerTlsContext)->withDefaultCertificate(new Certificate($certPath, $keyPath)),
));
mark('exposed');

$server->start(
    new ClosureRequestHandler(fn (): ServerResponse => new ServerResponse(200, [], 'h2 loopback ok')),
    new DefaultErrorHandler,
);
mark('server started');

$port = $server->getServers()[0]->getAddress()->getPort();
mark('listening on port '.$port);

$client = (new HttpClientBuilder)
    ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(null, (new ConnectContext)->withTlsContext(
        (new ClientTlsContext('127.0.0.1'))->withoutPeerVerification(),
    ))))
    ->build();
mark('client built');

$request = new ClientRequest("https://127.0.0.1:{$port}/");
$request->setProtocolVersions(['2']);
$request->setTcpConnectTimeout(5.0);
$request->setTlsHandshakeTimeout(5.0);
$request->setTransferTimeout(5.0);
mark('request prepared, sending');

try {
    $response = $client->request($request);
    mark('response status '.$response->getStatus().' proto '.$response->getProtocolVersion());
    mark('body: '.$response->getBody()->buffer());
} catch (Throwable $e) {
    mark('request threw '.$e::class.': '.$e->getMessage());
}

mark('stopping server');
$server->stop();
mark('server stopped');

@unlink($certPath);
@unlink($keyPath);
mark('done');
