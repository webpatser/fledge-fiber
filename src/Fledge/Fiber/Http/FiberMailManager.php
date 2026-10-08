<?php

namespace Fledge\Fiber\Http;

use Fledge\Fiber\Http\Symfony\FledgeSymfonyHttpClient;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Arr;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mail manager whose HTTP based transports (Postmark, Mailgun, Resend, ...)
 * run on the Fledge async client instead of Symfony's curl or native client.
 *
 * A mailer opts out with 'client' => ['fledge' => false]; any other keys of
 * its 'client' array are passed on as Symfony request options, except
 * Laravel's own `max_host_connections` (mapped to the Fledge per-host pool
 * limit, falling back to `fledge-http.pool_per_host.mail`) and
 * `max_pending_pushes` (no equivalent, dropped). Without symfony/http-client
 * installed the stock client is used.
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

        $fledge = Arr::pull($options, 'fledge', true);

        if ($fledge === false || ! trait_exists(HttpClientTrait::class)) {
            $config['client'] = $options;

            return parent::getHttpClient($config);
        }

        $maxHostConnections = Arr::pull($options, 'max_host_connections');
        Arr::pull($options, 'max_pending_pushes');

        $perHost = $maxHostConnections === null
            ? FiberHttpServiceProvider::perHost($this->app, 'mail')
            : ((int) $maxHostConnections > 0 ? (int) $maxHostConnections : null);

        return new FledgeSymfonyHttpClient($options, FledgeGuzzle::factory($perHost));
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
     * Inject the Fledge http_handler unless the mailer opted out or an
     * http_handler is set on the mailer or in `services.ses` (the parent
     * merges the mailer config over services.ses, so injecting here would
     * otherwise shadow it).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withSesHandler(array $config): array
    {
        if (isset($config['http_handler'])
            || ($config['client']['fledge'] ?? true) === false
            || $this->app['config']->get('services.ses.http_handler') !== null) {
            return $config;
        }

        $handler = FiberHttpServiceProvider::awsHttpHandler(FiberHttpServiceProvider::perHost($this->app, 'mail'));

        if ($handler !== null) {
            $config['http_handler'] = $handler;
        }

        return $config;
    }
}
