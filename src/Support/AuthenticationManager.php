<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Support;

use Daikazu\EloquentSalesforceObjects\Exceptions\AuthenticationException;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;
use Throwable;

class AuthenticationManager
{
    /**
     * Ensure valid Salesforce authentication exists
     *
     * Checks for existing valid token and authenticates if necessary.
     *
     * @throws AuthenticationException If authentication fails
     */
    public function ensureAuthenticated(): void
    {
        if (Forrest::hasToken()) {
            return;
        }

        $this->authenticate();
    }

    /**
     * Force fresh authentication bypassing token cache
     *
     * @throws AuthenticationException If authentication fails
     */
    public function forceReauthenticate(): void
    {
        $this->authenticate();
    }

    /**
     * Get current Salesforce instance URL
     *
     * @throws AuthenticationException If no valid token or instance URL available
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
     * Perform actual authentication with Salesforce
     *
     * @throws AuthenticationException If authentication fails
     */
    protected function authenticate(): void
    {
        try {
            Forrest::authenticate();
        } catch (Throwable $e) {
            throw new AuthenticationException(
                'Failed to authenticate with Salesforce: ' . $e->getMessage(),
                $e
            );
        }
    }
}
