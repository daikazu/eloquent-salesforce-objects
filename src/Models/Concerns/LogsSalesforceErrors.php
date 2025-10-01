<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Models\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

trait LogsSalesforceErrors
{
    /**
     * Log a Salesforce error
     */
    protected function logSalesforceError(string $message, array $context = [], ?string $level = null): void
    {
        $channel = config('eloquent-salesforce-objects.logging_channel');

        // If logging is explicitly disabled
        if ($channel === false) {
            return;
        }

        $level ??= config('eloquent-salesforce-objects.log_level', 'error');

        // Use configured channel or default
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->$level($message, $context);
    }

    /**
     * Handle a Salesforce exception based on configuration
     *
     * @throws Throwable
     */
    protected function handleSalesforceException(Throwable $exception, string $operation): void
    {
        $context = [
            'operation' => $operation,
            'model'     => static::class,
            'exception' => $exception::class,
            'message'   => $exception->getMessage(),
        ];

        // Always log the error
        $this->logSalesforceError(
            "Salesforce {$operation} operation failed: {$exception->getMessage()}",
            $context
        );

        // Throw exception if configured to do so
        if (config('eloquent-salesforce-objects.throw_exceptions', true)) {
            throw $exception;
        }
    }
}
