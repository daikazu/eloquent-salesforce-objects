<?php

use Daikazu\EloquentSalesforceObjects\Exceptions\AuthenticationException;
use Daikazu\EloquentSalesforceObjects\Support\AuthenticationManager;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);

    Forrest::swap($forrestMock);

    // Keep tests fast — no actual sleep between retries.
    config()->set('eloquent-salesforce-objects.authentication.retry_base_delay_ms', 0);
    config()->set('eloquent-salesforce-objects.authentication.retry_attempts', 3);
    config()->set('eloquent-salesforce-objects.authentication.lock_wait_seconds', 5);
    config()->set('eloquent-salesforce-objects.authentication.lock_ttl_seconds', 10);

    $this->manager = new AuthenticationManager;
});

afterEach(function () {
    Mockery::close();
});

/**
 * Build a Guzzle ClientException for the documented Salesforce
 * "unknown_error / retry your request" 400 response.
 */
function transientSalesforceAuthException(): ClientException
{
    return new ClientException(
        'Client error',
        new Request('POST', 'https://login.salesforce.com/services/oauth2/token'),
        new Response(400, [], '{"error":"unknown_error","error_description":"retry your request"}')
    );
}

function permanentSalesforceAuthException(): ClientException
{
    return new ClientException(
        'Client error',
        new Request('POST', 'https://login.salesforce.com/services/oauth2/token'),
        new Response(400, [], '{"error":"invalid_grant","error_description":"authentication failure"}')
    );
}

describe('ensureAuthenticated', function () {
    it('does not authenticate when token exists', function () {
        Forrest::shouldReceive('hasToken')
            ->once()
            ->andReturn(true);

        Forrest::shouldNotReceive('authenticate');

        $this->manager->ensureAuthenticated();
    });

    it('authenticates when no token exists', function () {
        // First call: outer fast-path check (returns false).
        // Second call: double-check inside the lock (still false).
        Forrest::shouldReceive('hasToken')
            ->twice()
            ->andReturn(false);

        Forrest::shouldReceive('authenticate')
            ->once();

        $this->manager->ensureAuthenticated();
    });

    it('skips authenticate when another worker populated the token during lock acquisition', function () {
        // Outer check sees no token, but by the time we hold the lock,
        // another worker has finished authenticating.
        Forrest::shouldReceive('hasToken')
            ->twice()
            ->andReturn(false, true);

        Forrest::shouldNotReceive('authenticate');

        $this->manager->ensureAuthenticated();
    });

    it('throws AuthenticationException after exhausting retries on transient errors', function () {
        Forrest::shouldReceive('hasToken')
            ->twice()
            ->andReturn(false);

        Forrest::shouldReceive('authenticate')
            ->times(3)
            ->andThrow(transientSalesforceAuthException());

        expect(fn () => $this->manager->ensureAuthenticated())
            ->toThrow(AuthenticationException::class);
    });

    it('retries on transient Salesforce unknown_error and succeeds', function () {
        Forrest::shouldReceive('hasToken')
            ->twice()
            ->andReturn(false);

        Forrest::shouldReceive('authenticate')
            ->times(2)
            ->andReturnUsing(function () {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    throw transientSalesforceAuthException();
                }
            });

        $this->manager->ensureAuthenticated();
    });

    it('retries on connection errors', function () {
        Forrest::shouldReceive('hasToken')
            ->twice()
            ->andReturn(false);

        $connectException = new ConnectException(
            'Connection refused',
            new Request('POST', 'https://login.salesforce.com/services/oauth2/token')
        );

        Forrest::shouldReceive('authenticate')
            ->times(2)
            ->andReturnUsing(function () use ($connectException) {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    throw $connectException;
                }
            });

        $this->manager->ensureAuthenticated();
    });

    it('retries on 5xx server errors', function () {
        Forrest::shouldReceive('hasToken')
            ->twice()
            ->andReturn(false);

        $serverException = new ServerException(
            'Server error',
            new Request('POST', 'https://login.salesforce.com/services/oauth2/token'),
            new Response(503, [], 'Service Unavailable')
        );

        Forrest::shouldReceive('authenticate')
            ->times(2)
            ->andReturnUsing(function () use ($serverException) {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    throw $serverException;
                }
            });

        $this->manager->ensureAuthenticated();
    });

    it('does not retry on permanent invalid_grant errors', function () {
        Forrest::shouldReceive('hasToken')
            ->twice()
            ->andReturn(false);

        // Should be called exactly once — no retry on permanent failure.
        Forrest::shouldReceive('authenticate')
            ->once()
            ->andThrow(permanentSalesforceAuthException());

        expect(fn () => $this->manager->ensureAuthenticated())
            ->toThrow(AuthenticationException::class);
    });

    it('does not retry on generic non-network errors', function () {
        Forrest::shouldReceive('hasToken')
            ->twice()
            ->andReturn(false);

        Forrest::shouldReceive('authenticate')
            ->once()
            ->andThrow(new RuntimeException('Auth failed'));

        expect(fn () => $this->manager->ensureAuthenticated())
            ->toThrow(AuthenticationException::class, 'Failed to authenticate with Salesforce: Auth failed');
    });
});

describe('forceReauthenticate', function () {
    it('always calls authenticate without checking token cache', function () {
        // No hasToken expectations — force should bypass the cache.
        Forrest::shouldReceive('authenticate')
            ->once();

        $this->manager->forceReauthenticate();
    });

    it('retries transient errors during forced re-authentication', function () {
        Forrest::shouldReceive('authenticate')
            ->times(2)
            ->andReturnUsing(function () {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    throw transientSalesforceAuthException();
                }
            });

        $this->manager->forceReauthenticate();
    });

    it('throws AuthenticationException when authentication fails permanently', function () {
        Forrest::shouldReceive('authenticate')
            ->once()
            ->andThrow(new RuntimeException('Auth failed'));

        expect(fn () => $this->manager->forceReauthenticate())
            ->toThrow(AuthenticationException::class, 'Failed to authenticate with Salesforce: Auth failed');
    });
});

describe('getInstanceUrl', function () {
    it('returns instance URL when available', function () {
        Forrest::shouldReceive('hasToken')
            ->once()
            ->andReturn(true);

        Forrest::shouldReceive('getInstanceURL')
            ->once()
            ->andReturn('https://instance.salesforce.com');

        $url = $this->manager->getInstanceUrl();

        expect($url)->toBe('https://instance.salesforce.com');
    });

    it('authenticates before getting URL if no token', function () {
        Forrest::shouldReceive('hasToken')
            ->twice()
            ->andReturn(false);

        Forrest::shouldReceive('authenticate')
            ->once();

        Forrest::shouldReceive('getInstanceURL')
            ->once()
            ->andReturn('https://instance.salesforce.com');

        $url = $this->manager->getInstanceUrl();

        expect($url)->toBe('https://instance.salesforce.com');
    });

    it('throws exception when instance URL is null', function () {
        Forrest::shouldReceive('hasToken')
            ->once()
            ->andReturn(true);

        Forrest::shouldReceive('getInstanceURL')
            ->once()
            ->andReturn(null);

        expect(fn () => $this->manager->getInstanceUrl())
            ->toThrow(AuthenticationException::class, 'No valid Salesforce instance URL available');
    });

    it('throws exception when authentication fails', function () {
        Forrest::shouldReceive('hasToken')
            ->twice()
            ->andReturn(false);

        Forrest::shouldReceive('authenticate')
            ->once()
            ->andThrow(new RuntimeException('Auth failed'));

        expect(fn () => $this->manager->getInstanceUrl())
            ->toThrow(AuthenticationException::class, 'Failed to authenticate with Salesforce: Auth failed');
    });

    it('throws exception when getInstanceURL throws unexpected error', function () {
        Forrest::shouldReceive('hasToken')
            ->once()
            ->andReturn(true);

        Forrest::shouldReceive('getInstanceURL')
            ->once()
            ->andThrow(new RuntimeException('Connection error'));

        expect(fn () => $this->manager->getInstanceUrl())
            ->toThrow(AuthenticationException::class, 'Failed to retrieve Salesforce instance URL: Connection error');
    });

    it('throws exception when instance URL is empty string', function () {
        Forrest::shouldReceive('hasToken')
            ->once()
            ->andReturn(true);

        Forrest::shouldReceive('getInstanceURL')
            ->once()
            ->andReturn('');

        expect(fn () => $this->manager->getInstanceUrl())
            ->toThrow(AuthenticationException::class, 'No valid Salesforce instance URL available');
    });
});
