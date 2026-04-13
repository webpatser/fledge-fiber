<?php declare(strict_types=1);

namespace Fledge\Async\Http\Client\Interceptor;

use Fledge\Async\Http\Client\HttpException;
use Fledge\Async\Http\Client\Response;

class TooManyRedirectsException extends HttpException
{
    private Response $response;

    public function __construct(Response $response)
    {
        parent::__construct("There were too many redirects");

        $this->response = $response;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }
}
