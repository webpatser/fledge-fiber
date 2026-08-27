<?php

use Fledge\Async\Redis\RedisConfig;
use Fledge\Async\Stream\ClientTlsContext;

function tlsConfig(array $context = []): RedisConfig
{
    return RedisConfig::fromParameters([
        'scheme' => 'tls',
        'host' => 'redis.example.com',
        'port' => 6380,
        'context' => $context,
    ]);
}

it('returns null when the connection does not use tls', function () {
    $config = RedisConfig::fromParameters(['host' => 'localhost']);

    expect($config->getTlsContext())->toBeNull();
});

it('defaults the peer name to the host', function () {
    expect(tlsConfig()->getTlsContext()->getPeerName())->toBe('redis.example.com');
});

it('maps peer_name', function () {
    expect(tlsConfig(['peer_name' => 'other.example.com'])->getTlsContext()->getPeerName())
        ->toBe('other.example.com');
});

it('verifies the peer by default', function () {
    expect(tlsConfig()->getTlsContext()->hasPeerVerification())->toBeTrue();
});

it('maps verify_peer false to disabled peer verification', function () {
    expect(tlsConfig(['verify_peer' => false])->getTlsContext()->hasPeerVerification())->toBeFalse();
});

it('maps verify_peer_name false to disabled peer verification', function () {
    expect(tlsConfig(['verify_peer_name' => false])->getTlsContext()->hasPeerVerification())->toBeFalse();
});

it('maps cafile', function () {
    expect(tlsConfig(['cafile' => '/tmp/ca.pem'])->getTlsContext()->getCaFile())->toBe('/tmp/ca.pem');
});

it('maps capath', function () {
    expect(tlsConfig(['capath' => '/tmp/certs'])->getTlsContext()->getCaPath())->toBe('/tmp/certs');
});

it('maps verify_depth', function () {
    expect(tlsConfig(['verify_depth' => 3])->getTlsContext()->getVerificationDepth())->toBe(3);
});

it('maps ciphers', function () {
    expect(tlsConfig(['ciphers' => 'ECDHE-RSA-AES128-GCM-SHA256'])->getTlsContext()->getCiphers())
        ->toBe('ECDHE-RSA-AES128-GCM-SHA256');
});

it('maps local_cert, local_pk and passphrase to a client certificate', function () {
    $certificate = tlsConfig([
        'local_cert' => '/tmp/client.pem',
        'local_pk' => '/tmp/client.key',
        'passphrase' => 'secret',
    ])->getTlsContext()->getCertificate();

    expect($certificate)->not->toBeNull()
        ->and($certificate->getCertFile())->toBe('/tmp/client.pem')
        ->and($certificate->getKeyFile())->toBe('/tmp/client.key')
        ->and($certificate->getPassphrase())->toBe('secret');
});

it('maps local_cert alone to a combined certificate and key file', function () {
    $certificate = tlsConfig(['local_cert' => '/tmp/client.pem'])->getTlsContext()->getCertificate();

    expect($certificate)->not->toBeNull()
        ->and($certificate->getCertFile())->toBe('/tmp/client.pem')
        ->and($certificate->getKeyFile())->toBe('/tmp/client.pem');
});

it('maps security_level', function () {
    expect(tlsConfig(['security_level' => 3])->getTlsContext()->getSecurityLevel())->toBe(3);
});

it('maps a single peer_fingerprint string', function () {
    $sha256 = str_repeat('a', 64);

    expect(tlsConfig(['peer_fingerprint' => $sha256])->getTlsContext()->getPeerFingerprints())
        ->toBe(['sha256' => $sha256]);
});

it('maps a peer_fingerprint array', function () {
    $fingerprints = ['sha1' => str_repeat('b', 40)];

    expect(tlsConfig(['peer_fingerprint' => $fingerprints])->getTlsContext()->getPeerFingerprints())
        ->toBe($fingerprints);
});

it('reuses the lazily built context', function () {
    $config = tlsConfig(['verify_peer' => false]);

    expect($config->getTlsContext())->toBeInstanceOf(ClientTlsContext::class)
        ->and($config->getTlsContext())->toBe($config->getTlsContext());
});
