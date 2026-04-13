<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Internal;

use Fledge\Async\ForbidCloning;
use Fledge\Async\ForbidSerialization;

/** @internal */
final class HarAttributes
{
    use ForbidCloning;
    use ForbidSerialization;

    public const STARTED_DATE_TIME = 'fledge.http.client.har.startedDateTime';
    public const SERVER_IP_ADDRESS = 'fledge.http.client.har.serverIPAddress';

    public const TIME_START = 'fledge.http.client.har.timings.start';
    public const TIME_SSL = 'fledge.http.client.har.timings.ssl';
    public const TIME_CONNECT = 'fledge.http.client.har.timings.connect';
    public const TIME_SEND = 'fledge.http.client.har.timings.send';
    public const TIME_WAIT = 'fledge.http.client.har.timings.wait';
    public const TIME_RECEIVE = 'fledge.http.client.har.timings.receive';
    public const TIME_COMPLETE = 'fledge.http.client.har.timings.complete';

    public const INCLUDE_CONNECT_TIME = 'fledge.http.client.har.timings.connect.include';
}
