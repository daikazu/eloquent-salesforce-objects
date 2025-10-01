<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Exceptions;

use Throwable;

class AuthenticationException extends SalesforceException
{
    /**
     * Create a new authentication exception instance
     *
     * @param  string  $message  The exception message
     * @param  Throwable|null  $previous  The previous exception for exception chaining
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
