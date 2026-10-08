<?php

namespace Fledge\Fiber\Http;

use Aws\Handler\GuzzleV6\GuzzleHandler;
use Aws\Sdk;
use Fledge\Fiber\Http\Symfony\FledgeSymfonyHttpClient;
use Illuminate\Mail\MailManager;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mail manager whose HTTP based transports (Postmark, Mailgun, Resend, ...)
 * run on the Fledge async client instead of Symfony's curl or native client.
 *
 * A mailer opts out with 'client' => ['fledge' => false]; any other keys of
 * its 'client' array are passed on as Symfony request options.
 */
class FiberMailManager extends MailManager
{
    /**
     * @param  array<string, mixed>  $config
     */
    protected function getHttpClient(array $config): ?HttpClientInterface
    {
        $options = $config['client'] ?? [];

        if (! \is_array($options)) {
            $options = [];
        }

        $fledge = $options['fledge'] ?? true;
        unset($options['fledge']);

        if ($fledge === false) {
            $config['client'] = $options;

            return parent::getHttpClient($config);
        }

        return new FledgeSymfonyHttpClient($options);
    }

    /**
     * SES: hand the AWS SDK a Guzzle handler running on the Fledge stack,
     * unless the mailer config already supplies an http_handler.
     *
     * @param  array<string, mixed>  $config
     */
    protected function createSesTransport(array $config)
    {
        return parent::createSesTransport($this->withSesHandler($config));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createSesV2Transport(array $config)
    {
        return parent::createSesV2Transport($this->withSesHandler($config));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withSesHandler(array $config): array
    {
        if (class_exists(Sdk::class) && ($config['client']['fledge'] ?? true) !== false) {
            $config['http_handler'] ??= new GuzzleHandler(FledgeGuzzle::client());
        }

        return $config;
    }
}
