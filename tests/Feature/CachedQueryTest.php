<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Illuminate\Support\Facades\Cache;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    // Mock the Forrest service
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);

    // Clear cache
    Cache::flush();

    // Enable caching
    config([
        'eloquent-salesforce-objects.query_cache.enabled'                          => true,
        'eloquent-salesforce-objects.query_cache.default_ttl'                      => 3600,
        'eloquent-salesforce-objects.query_cache.auto_invalidate_on_local_changes' => false, // Disable for tests
    ]);
});

afterEach(function () {
    Mockery::close();
    Cache::flush();
});

describe('query caching integration', function () {
    it('caches query results automatically', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Mock query to be called only ONCE (first call)
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '001xx000001',
                        'Name'       => 'Account 1',
                        'attributes' => ['type' => 'Account'],
                    ],
                    [
                        'Id'         => '001xx000002',
                        'Name'       => 'Account 2',
                        'attributes' => ['type' => 'Account'],
                    ],
                ],
            ]);

        // First call - should hit API
        $accounts1 = Account::where('Industry', 'Technology')->get();
        expect($accounts1)->toHaveCount(2);

        // Second call - should use cache (Forrest::query called only once)
        $accounts2 = Account::where('Industry', 'Technology')->get();
        expect($accounts2)->toHaveCount(2);
        expect($accounts2[0]->Name)->toBe('Account 1');
    });

    it('caches different queries separately', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // First query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(fn ($q) => str_contains($q, 'Technology')))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Tech Co', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        // Second query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(fn ($q) => str_contains($q, 'Finance')))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000002', 'Name' => 'Finance Co', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $techAccounts = Account::where('Industry', 'Technology')->get();
        $financeAccounts = Account::where('Industry', 'Finance')->get();

        expect($techAccounts[0]->Name)->toBe('Tech Co');
        expect($financeAccounts[0]->Name)->toBe('Finance Co');
    });
});

describe('withoutCache method', function () {
    it('bypasses cache when withoutCache is used', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Mock query to be called TWICE (no caching)
        Forrest::shouldReceive('query')
            ->twice()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Account 1', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        // First call with withoutCache
        $accounts1 = Account::withoutCache()->where('Industry', 'Tech')->get();
        expect($accounts1)->toHaveCount(1);

        // Second call with withoutCache - should hit API again
        $accounts2 = Account::withoutCache()->where('Industry', 'Tech')->get();
        expect($accounts2)->toHaveCount(1);
    });
});

describe('cacheFor method', function () {
    it('sets custom TTL for query', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Account 1', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        // Query with custom 10-minute TTL
        $accounts = Account::cacheFor(600)->where('Industry', 'Tech')->get();

        expect($accounts)->toHaveCount(1);

        // Verify result is cached (second call doesn't hit API)
        $accounts2 = Account::cacheFor(600)->where('Industry', 'Tech')->get();
        expect($accounts2)->toHaveCount(1);
    });
});

describe('refreshCache method', function () {
    it('forces cache refresh', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // First call
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Old Name', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $accounts1 = Account::where('Industry', 'Tech')->get();
        expect($accounts1[0]->Name)->toBe('Old Name');

        // Second call with refreshCache - should hit API again with new data
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'New Name', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $accounts2 = Account::refreshCache()->where('Industry', 'Tech')->get();
        expect($accounts2[0]->Name)->toBe('New Name');
    });
});

describe('cacheTags method', function () {
    it('adds custom tags to cached query', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Tagged Account', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        // Query with custom tags
        $accounts = Account::cacheTags(['important', 'priority'])
            ->where('Industry', 'Tech')
            ->get();

        expect($accounts)->toHaveCount(1);

        // Verify it's cached
        $accounts2 = Account::cacheTags(['important', 'priority'])
            ->where('Industry', 'Tech')
            ->get();
        expect($accounts2)->toHaveCount(1);
    });
});

describe('method chaining', function () {
    it('allows chaining cache methods with query builder', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Industry'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Account 1', 'Industry' => 'Tech', 'attributes' => ['type' => 'Account']],
                    ['Id' => '001xx000002', 'Name' => 'Account 2', 'Industry' => 'Tech', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $accounts = Account::cacheFor(300)
            ->cacheTags(['tech-sector'])
            ->where('Industry', 'Technology')
            ->orderBy('Name')
            ->limit(10)
            ->get();

        expect($accounts)->toHaveCount(2);
    });
});

describe('caching with disabled config', function () {
    it('does not cache when caching is disabled', function () {
        config(['eloquent-salesforce-objects.query_cache.enabled' => false]);

        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Query should be called TWICE (no caching)
        Forrest::shouldReceive('query')
            ->twice()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Account 1', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $accounts1 = Account::where('Industry', 'Tech')->get();
        $accounts2 = Account::where('Industry', 'Tech')->get();

        expect($accounts1)->toHaveCount(1);
        expect($accounts2)->toHaveCount(1);
    });
});

describe('aggregate query caching', function () {
    it('never caches aggregate queries', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // COUNT query should be called TWICE (never cached)
        Forrest::shouldReceive('query')
            ->twice()
            ->andReturn([
                'totalSize' => 150,
                'done'      => true,
                'records'   => [],
            ]);

        // First count - hits API
        $count1 = Account::count();
        expect($count1)->toBe(150);

        // Second count - hits API again (not cached)
        $count2 = Account::count();
        expect($count2)->toBe(150);
    });
});
