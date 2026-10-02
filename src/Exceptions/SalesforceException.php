<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Exceptions;

use Exception;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use Throwable;

class SalesforceException extends Exception
{
    /**
     * @param  int|null  $statusCode  HTTP status of the failed Salesforce request, when there was one
     * @param  string|null  $errorCode  Salesforce error code, e.g. "MALFORMED_QUERY" or "REQUEST_LIMIT_EXCEEDED"
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Wrap a failed API call, describing the HTTP response if there was one.
     *
     * Forrest JSON-decodes error bodies for its message, so a non-JSON body (an HTML
     * error page, or none at all) arrives as the message "null". The response is
     * still attached to the exception, so read the status and body from there.
     *
     * @param  string  $context  What failed, e.g. "Query failed"
     */
    public static function fromThrowable(string $context, Throwable $e): self
    {
        $response = null;

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RequestException && $current->hasResponse()) {
                $response = $current->getResponse();
                break;
            }
        }

        if ($response === null) {
            return new self("{$context}: " . ($e->getMessage() !== '' ? $e->getMessage() : $e::class), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = trim((string) $response->getBody());
        $errors = self::salesforceErrors($body);

        if ($errors !== []) {
            $detail = implode('; ', array_map(
                fn (array $error): string => trim("{$error['errorCode']}: {$error['message']}", ': '),
                $errors
            ));
            $errorCode = $errors[0]['errorCode'] !== '' ? $errors[0]['errorCode'] : null;
            $class = $errorCode === 'MALFORMED_QUERY' ? MalformedQueryException::class : self::class;

            return new $class("{$context}: {$detail} (HTTP {$status})", 0, $e, $status, $errorCode);
        }

        $detail = trim("HTTP {$status} " . $response->getReasonPhrase());

        if ($status === 414 || $status === 431) {
            $detail .= ' (the request is too long: Forrest sends SOQL in the URL, so select fewer columns or filter on fewer values)';
        } elseif ($body !== '') {
            $detail .= ': ' . Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($body))), 300);
        }

        return new self("{$context}: {$detail}", 0, $e, $status);
    }

    /**
     * Read Salesforce's error formats: a list of {errorCode, message}, or an OAuth {error, error_description}.
     *
     * @return array<int, array{errorCode: string, message: string}>
     */
    private static function salesforceErrors(string $body): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return [['errorCode' => $decoded['error'], 'message' => (string) ($decoded['error_description'] ?? '')]];
        }

        if (! array_is_list($decoded)) {
            return [];
        }

        $errors = [];

        foreach ($decoded as $error) {
            if (is_array($error) && isset($error['message'])) {
                $errors[] = ['errorCode' => (string) ($error['errorCode'] ?? ''), 'message' => (string) $error['message']];
            }
        }

        return $errors;
    }
}
