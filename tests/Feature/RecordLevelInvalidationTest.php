<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Support\QueryCache;
use Illuminate\Support\Facades\Cache;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    // Mock the Forrest service
    $forrestMock = Mockery::mock('Omniphx\\Forrest\\Interfaces\\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);

    // Clear cache
    Cache::flush();

    // Enable caching with record-level invalidation strategy
    config([
        'eloquent-salesforce-objects.query_cache.enabled'                          => true,
        'eloquent-salesforce-objects.query_cache.default_ttl'                      => 3600,
        'eloquent-salesforce-objects.query_cache.invalidation_strategy'            => 'record',
        'eloquent-salesforce-objects.query_cache.auto_invalidate_on_local_changes' => true,
    ]);
});

afterEach(function () {
    Mockery::close();
    Cache::flush();
});

describe('record ID tracking', function () {
    it('tracks record IDs when caching query results', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id', 'updateable' => false],
                ['name' => 'Name', 'updateable' => true],
                ['name' => 'Industry', 'updateable' => true],
                ['name' => 'CreatedDate', 'updateable' => false],
                ['name' => 'LastModifiedDate', 'updateable' => false],
                ['name' => 'IsDeleted', 'updateable' => false],
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

        // Query and cache
        $accounts = Account::where('Industry', 'Tech')->get();
        expect($accounts)->toHaveCount(2);

        // Verify record-to-cachekey mappings were created
        $recordKey1 = Cache::get('sf_record_Account_001xx000001');
        $recordKey2 = Cache::get('sf_record_Account_001xx000002');

        expect($recordKey1)->toBeArray();
        expect($recordKey2)->toBeArray();
        expect(count($recordKey1))->toBeGreaterThan(0);
        expect(count($recordKey2))->toBeGreaterThan(0);
    });

    it('does not track record IDs when strategy is object', function () {
        config(['eloquent-salesforce-objects.query_cache.invalidation_strategy' => 'object']);

        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id', 'updateable' => false],
                ['name' => 'Name', 'updateable' => true],
                ['name' => 'Industry', 'updateable' => true],
                ['name' => 'CreatedDate', 'updateable' => false],
                ['name' => 'LastModifiedDate', 'updateable' => false],
                ['name' => 'IsDeleted', 'updateable' => false],
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

        Account::where('Industry', 'Tech')->get();

        // Verify no record tracking
        expect(Cache::has('sf_record_Account_001xx000001'))->toBeFalse();
    });
});

describe('surgical invalidation on update', function () {
    it('invalidateByRecordIds removes only affected cache entries', function () {
        $queryCache = app(QueryCache::class);

        // Manually create cache entries with record tracking
        $cacheKey1 = 'sf_query_test1';
        $cacheKey2 = 'sf_query_test2';

        // Cache entry 1: Contains Account 001
        Cache::put($cacheKey1, ['data' => 'query1'], 3600);
        Cache::put('sf_record_Account_001xx000001', [$cacheKey1], 3600);

        // Cache entry 2: Contains Account 002 (different record)
        Cache::put($cacheKey2, ['data' => 'query2'], 3600);
        Cache::put('sf_record_Account_001xx000002', [$cacheKey2], 3600);

        // Verify both are cached
        expect(Cache::has($cacheKey1))->toBeTrue();
        expect(Cache::has($cacheKey2))->toBeTrue();

        // Invalidate by record ID 001
        $queryCache->invalidateByRecordIds('Account', ['001xx000001']);

        // Cache entry 1 should be invalidated
        expect(Cache::has($cacheKey1))->toBeFalse();

        // Cache entry 2 should still exist (different record)
        expect(Cache::has($cacheKey2))->toBeTrue();
    });
});

describe('surgical invalidation on delete', function () {
    it('invalidates multiple cache entries for same record', function () {
        $queryCache = app(QueryCache::class);

        // Create multiple cache entries that contain the same record
        $cacheKey1 = 'sf_query_all_accounts';
        $cacheKey2 = 'sf_query_tech_accounts';
        $cacheKey3 = 'sf_query_finance_accounts';

        Cache::put($cacheKey1, ['data' => 'all'], 3600);
        Cache::put($cacheKey2, ['data' => 'tech'], 3600);
        Cache::put($cacheKey3, ['data' => 'finance'], 3600);

        // Account 001 appears in both "all" and "tech" queries
        Cache::put('sf_record_Account_001xx000001', [$cacheKey1, $cacheKey2], 3600);

        // Account 002 only in "finance" query
        Cache::put('sf_record_Account_001xx000002', [$cacheKey3], 3600);

        // Invalidate Account 001
        $queryCache->invalidateByRecordIds('Account', ['001xx000001']);

        // Queries containing Account 001 should be invalidated
        expect(Cache::has($cacheKey1))->toBeFalse();
        expect(Cache::has($cacheKey2))->toBeFalse();

        // Query without Account 001 should still exist
        expect(Cache::has($cacheKey3))->toBeTrue();
    });
});

describe('webhook record-level invalidation', function () {
    it('uses record-level invalidation when processing webhook', function () {
        config([
            'eloquent-salesforce-objects.query_cache.webhook_invalidation' => true,
            'eloquent-salesforce-objects.query_cache.webhook_secret'       => 'test-secret',
        ]);

        // Webhook: Account 1 was updated in Salesforce
        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName' => 'Account',
                    'recordIds'  => ['001xx000001'], // Only Account 1 changed
                    'changeType' => 'UPDATE',
                ],
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'test-secret',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success'           => true,
                'invalidation_type' => 'record-level',
            ]);
    });

    it('falls back to object-level when no record IDs in webhook', function () {
        config([
            'eloquent-salesforce-objects.query_cache.webhook_invalidation' => true,
            'eloquent-salesforce-objects.query_cache.webhook_secret'       => 'test-secret',
        ]);

        $payload = [
            'payload' => [
                'ChangeEventHeader' => [
                    'entityName' => 'Account',
                    'recordIds'  => [], // No record IDs
                    'changeType' => 'UPDATE',
                ],
            ],
        ];

        $response = $this->postJson('/api/salesforce/webhooks/cdc', $payload, [
            'X-Salesforce-Webhook-Secret' => 'test-secret',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success'           => true,
                'invalidation_type' => 'object-level', // Fallback
            ]);
    });
});

describe('invalidation strategy configuration', function () {
    it('uses object-level invalidation when configured', function () {
        config(['eloquent-salesforce-objects.query_cache.invalidation_strategy' => 'object']);

        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id', 'updateable' => false],
                ['name' => 'Name', 'updateable' => true],
                ['name' => 'Industry', 'updateable' => true],
                ['name' => 'CreatedDate', 'updateable' => false],
                ['name' => 'LastModifiedDate', 'updateable' => false],
                ['name' => 'IsDeleted', 'updateable' => false],
            ],
        ]);

        // Cache two separate queries
        Forrest::shouldReceive('query')
            ->twice()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Account 1', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        Account::where('Id', '001xx000001')->first();
        Account::where('Id', '001xx000002')->first();

        // Update Account 1 (with object-level strategy, both queries should be invalidated)
        $account = new Account(['Id' => '001xx000001', 'Name' => 'Account 1']);
        $account->exists = true;
        $account->syncOriginal();
        $account->Name = 'Updated';

        Forrest::shouldReceive('sobjects')->once()->andReturn([]);
        $account->save();

        // Both queries should hit API (entire object cache was flushed)
        Forrest::shouldReceive('query')
            ->twice()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Updated Account 1', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        Account::where('Id', '001xx000001')->first();
        Account::where('Id', '001xx000002')->first();

        // Both queries hit API (verified by shouldReceive('query')->twice())
        expect(true)->toBeTrue();
    });
});
