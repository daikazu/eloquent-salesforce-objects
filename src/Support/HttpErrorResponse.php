<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Support;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Reads the HTTP response carried by a failed request, on Guzzle 7 and 8.
 *
 * Guzzle 7 keeps the response on RequestException (getResponse()/hasResponse()).
 * Guzzle 8 moves it to the ResponseException subclass and drops hasResponse(),
 * so a plain RequestException no longer has either method. Forrest's own
 * exceptions extend RequestException and expose getResponse()/hasResponse()
 * on both majors.
 */
class HttpErrorResponse
{
    public static function from(Throwable $e): ?ResponseInterface
    {
        return $e instanceof RequestException ? self::fromRequestException($e) : null;
    }

    /**
     * Typed as object on purpose: which of the two methods exist depends on the
     * installed Guzzle major, so they are looked up by name.
     */
    private static function fromRequestException(object $e): ?ResponseInterface
    {
        if (! method_exists($e, 'getResponse')) {
            return null;
        }

        if (method_exists($e, 'hasResponse') && ! $e->hasResponse()) {
            return null;
        }

        $response = $e->getResponse();

        return $response instanceof ResponseInterface ? $response : null;
    }
}
