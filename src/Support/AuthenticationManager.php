<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Support;

use Daikazu\EloquentSalesforceObjects\Exceptions\AuthenticationException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;
use Throwable;

class AuthenticationManager
{
    /**
     * Forrest's authentication flow that calls SOAP API login() instead of OAuth.
     */
    public const string SOAP_LOGIN_FLOW = 'UserPasswordSoap';

    public const string SOAP_LOGIN_WARNING = 'Salesforce authentication is using SOAP API login() (forrest.authentication = "UserPasswordSoap"). '
        . 'Salesforce is retiring SOAP API login() in API versions 31.0-64.0 with the Summer \'27 release, it is disabled by default in orgs '
        . 'created in Summer \'26 or later, and from Winter \'27 it requires the "Use Any API Auth" user permission. '
        . 'Switch forrest.authentication to an OAuth flow such as ClientCredentials or OAuthJWT.';

    /**
     * Ensure valid Salesforce authentication exists.
     *
     * Uses a single-flight cache lock so concurrent workers don't all
     * hammer the Salesforce OAuth endpoint when the token cache misses.
     *
     * @throws AuthenticationException
     */
    public function ensureAuthenticated(): void
    {
        if (Forrest::hasToken()) {
            return;
        }

        try {
            Cache::lock($this->lockKey(), $this->lockTtlSeconds())
                ->block($this->lockWaitSeconds(), function (): void {
                    // Double-check: another worker may have authenticated
                    // while we were waiting for the lock.
                    if (Forrest::hasToken()) {
                        return;
                    }

                    $this->performAuthentication();
                });
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (LockTimeoutException $e) {
            throw new AuthenticationException(
                'Timed out waiting for Salesforce authentication lock',
                $e
            );
        }
    }

    /**
     * Force fresh authentication, bypassing the token cache check.
     *
     * Intended for callers that have already determined the current
     * token is invalid (e.g. INVALID_SESSION_ID on an API call).
     *
     * @throws AuthenticationException
     */
    public function forceReauthenticate(): void
    {
        $this->performAuthentication();
    }

    /**
     * Get the current Salesforce instance URL.
     *
     * @throws AuthenticationException
     */
    public function getInstanceUrl(): string
    {
        $this->ensureAuthenticated();

        try {
            $url = Forrest::getInstanceURL();

            if (empty($url)) {
                throw new AuthenticationException('No valid Salesforce instance URL available');
            }

            return $url;
        } catch (Throwable $e) {
            if ($e instanceof AuthenticationException) {
                throw $e;
            }

            throw new AuthenticationException(
                'Failed to retrieve Salesforce instance URL: ' . $e->getMessage(),
                $e
            );
        }
    }

    /**
     * Call Forrest::authenticate() with retry on transient failures.
     *
     * @throws AuthenticationException
     */
    protected function performAuthentication(): void
    {
        $this->warnIfUsingSoapLogin();

        $maxAttempts = max(1, $this->retryAttempts());
        $baseDelayMs = max(0, $this->retryBaseDelayMs());

        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                Forrest::authenticate();

                return;
            } catch (Throwable $e) {
                $lastException = $e;

                if ($attempt >= $maxAttempts || ! $this->isRetryable($e)) {
                    break;
                }

                $this->sleepBetweenAttempts($attempt, $baseDelayMs);
            }
        }

        throw new AuthenticationException(
            'Failed to authenticate with Salesforce: ' . $lastException->getMessage(),
            $lastException
        );
    }

    /**
     * Whether Forrest is configured to authenticate with SOAP API login()
     * rather than an OAuth flow.
     */
    public function usesSoapLogin(): bool
    {
        return config('forrest.authentication') === self::SOAP_LOGIN_FLOW;
    }

    /**
     * Log a warning when authenticating with SOAP API login(), which Salesforce is retiring.
     * Honours the package's logging_channel setting (false disables logging).
     */
    protected function warnIfUsingSoapLogin(): void
    {
        if (! $this->usesSoapLogin()) {
            return;
        }

        $channel = config('eloquent-salesforce-objects.logging_channel');

        if ($channel === false) {
            return;
        }

        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->warning(self::SOAP_LOGIN_WARNING);
    }

    /**
     * Decide whether an authentication failure is worth retrying.
     *
     * Retry on:
     *  - Connection errors (network/TLS blips)
     *  - HTTP 5xx responses
     *  - Salesforce's documented transient envelope:
     *    400 { "error": "unknown_error", "error_description": "retry your request" }
     *
     * Do NOT retry on:
     *  - invalid_grant, invalid_client_id, authentication failure
     *    (credential / configuration problems — retrying makes it worse)
     *  - Any other 4xx or unrecognised error
     */
    protected function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectException) {
            return true;
        }

        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();

            // Salesforce's documented "try again" response on the OAuth endpoint.
            if ($status === 400 && str_contains($body, '"unknown_error"')) {
                return true;
            }

            return $status >= 500;
        }

        return false;
    }

    /**
     * Sleep with exponential backoff plus jitter between retry attempts.
     */
    protected function sleepBetweenAttempts(int $attempt, int $baseDelayMs): void
    {
        if ($baseDelayMs <= 0) {
            return;
        }

        $backoff = $baseDelayMs * (2 ** ($attempt - 1));
        $jitter = random_int(0, $baseDelayMs);

        usleep(($backoff + $jitter) * 1000);
    }

    /**
     * Lock key scoped to the connected app so multiple Salesforce orgs
     * don't share the same lock.
     */
    protected function lockKey(): string
    {
        $consumerKey = (string) config('forrest.credentials.consumerKey', '');
        $suffix = $consumerKey !== '' ? ':' . sha1($consumerKey) : '';

        return 'salesforce:auth:lock' . $suffix;
    }

    protected function retryAttempts(): int
    {
        return (int) config('eloquent-salesforce-objects.authentication.retry_attempts', 3);
    }

    protected function retryBaseDelayMs(): int
    {
        return (int) config('eloquent-salesforce-objects.authentication.retry_base_delay_ms', 250);
    }

    protected function lockWaitSeconds(): int
    {
        return (int) config('eloquent-salesforce-objects.authentication.lock_wait_seconds', 8);
    }

    protected function lockTtlSeconds(): int
    {
        return (int) config('eloquent-salesforce-objects.authentication.lock_ttl_seconds', 10);
    }
}
