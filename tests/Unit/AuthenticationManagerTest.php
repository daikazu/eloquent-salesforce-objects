<?php

use Daikazu\EloquentSalesforceObjects\Exceptions\AuthenticationException;
use Daikazu\EloquentSalesforceObjects\Support\AuthenticationManager;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    // Mock the Forrest service in the container
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);

    Forrest::swap($forrestMock);

    $this->manager = new AuthenticationManager;
});

afterEach(function () {
    Mockery::close();
});

describe('ensureAuthenticated', function () {
    it('does not authenticate when token exists', function () {
        Forrest::shouldReceive('hasToken')
            ->once()
            ->andReturn(true);

        Forrest::shouldNotReceive('authenticate');

        $this->manager->ensureAuthenticated();
    });

    it('authenticates when no token exists', function () {
        Forrest::shouldReceive('hasToken')
            ->once()
            ->andReturn(false);

        Forrest::shouldReceive('authenticate')
            ->once();

        $this->manager->ensureAuthenticated();
    });

    it('throws AuthenticationException when authentication fails', function () {
        Forrest::shouldReceive('hasToken')
            ->once()
            ->andReturn(false);

        Forrest::shouldReceive('authenticate')
            ->once()
            ->andThrow(new RuntimeException('Auth failed'));

        expect(fn () => $this->manager->ensureAuthenticated())
            ->toThrow(AuthenticationException::class, 'Failed to authenticate with Salesforce: Auth failed');
    });
});

describe('forceReauthenticate', function () {
    it('always calls authenticate', function () {
        Forrest::shouldReceive('authenticate')
            ->once();

        $this->manager->forceReauthenticate();
    });

    it('throws AuthenticationException when authentication fails', function () {
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
            ->once()
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
            ->once()
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
