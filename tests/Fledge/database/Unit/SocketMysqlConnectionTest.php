<?php

use Fledge\Async\Database\Mysql\MysqlConfig;
use Fledge\Async\Database\Mysql\SocketMysqlConnection;
use Fledge\Async\Database\SqlException;
use Fledge\Async\Stream\ClientTlsContext;
use Fledge\Async\Stream\ConnectContext;
use Tests\Fledge\database\Stubs\CapturingSocketConnector;

function captureMysqlConnectContext(MysqlConfig $config): CapturingSocketConnector
{
    $connector = new CapturingSocketConnector;

    try {
        SocketMysqlConnection::connect($connector, $config);
    } catch (SqlException) {
        // The capturing connector refuses every connection after recording it.
    }

    return $connector;
}

it('enables TCP_NODELAY for tcp connections', function () {
    $connector = captureMysqlConnectContext(new MysqlConfig('127.0.0.1', 3306));

    expect($connector->uri)->toBe('tcp://127.0.0.1:3306')
        ->and($connector->context->hasTcpNoDelay())->toBeTrue()
        ->and($connector->context->toStreamContextArray()['socket']['tcp_nodelay'])->toBeTrue();
});

it('leaves unix socket connections untouched', function () {
    $config = new MysqlConfig('/tmp/mysql.sock');
    $connector = captureMysqlConnectContext($config);

    expect($connector->uri)->toBe('unix:///tmp/mysql.sock')
        ->and($connector->context)->toBe($config->getConnectContext())
        ->and($connector->context->hasTcpNoDelay())->toBeFalse();
});

it('preserves caller supplied context options alongside TCP_NODELAY', function () {
    $tls = (new ClientTlsContext)->withPeerName('db.internal')->withCaFile('/etc/ssl/ca.pem');
    $context = (new ConnectContext)
        ->withTlsContext($tls)
        ->withConnectTimeout(3)
        ->withBindTo('10.0.0.5:0');

    $config = new MysqlConfig('db.internal', 3307, context: $context);
    $connector = captureMysqlConnectContext($config);

    expect($connector->context->hasTcpNoDelay())->toBeTrue()
        ->and($connector->context->getTlsContext())->toBe($tls)
        ->and($connector->context->getTlsContext()->getPeerName())->toBe('db.internal')
        ->and($connector->context->getTlsContext()->getCaFile())->toBe('/etc/ssl/ca.pem')
        ->and($connector->context->getConnectTimeout())->toBe(3.0)
        ->and($connector->context->getBindTo())->toBe('10.0.0.5:0')
        ->and($config->getConnectContext()->hasTcpNoDelay())->toBeFalse();
});
