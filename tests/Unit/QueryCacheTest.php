<?php

use Daikazu\EloquentSalesforceObjects\Support\QueryCache;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Clear cache before each test
    Cache::flush();

    // Set test configuration
    config([
        'eloquent-salesforce-objects.query_cache' => [
            'enabled'       => true,
            'default_ttl'   => 3600,
            'driver'        => null,
            'ttl_overrides' => [
                'Account'     => 7200,
                'Opportunity' => 1800,
            ],
            'auto_invalidate_on_local_changes' => true,
        ],
        'eloquent-salesforce-objects.enable_query_log' => false,
    ]);
});

afterEach(function () {
    Cache::flush();
});

describe('QueryCache initialization', function () {
    it('initializes with correct default settings', function () {
        $cache = new QueryCache;

        expect($cache->isEnabled())->toBeTrue();
    });

    it('respects disabled configuration', function () {
        config(['eloquent-salesforce-objects.query_cache.enabled' => false]);

        $cache = new QueryCache;

        expect($cache->isEnabled())->toBeFalse();
    });
});

describe('cache key generation', function () {
    it('generates consistent cache keys for identical queries', function () {
        $cache = new QueryCache;
        $query1 = "SELECT Id, Name FROM Account WHERE Industry = 'Technology'";
        $query2 = "SELECT Id, Name FROM Account WHERE Industry = 'Technology'";

        // Use reflection to test protected method
        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('generateCacheKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($cache, $query1);
        $key2 = $method->invoke($cache, $query2);

        expect($key1)->toBe($key2);
    });

    it('generates different cache keys for different queries', function () {
        $cache = new QueryCache;
        $query1 = "SELECT Id, Name FROM Account WHERE Industry = 'Technology'";
        $query2 = "SELECT Id, Name FROM Account WHERE Industry = 'Finance'";

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('generateCacheKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($cache, $query1);
        $key2 = $method->invoke($cache, $query2);

        expect($key1)->not->toBe($key2);
    });

    it('normalizes queries before generating cache keys', function () {
        $cache = new QueryCache;
        $query1 = '  SELECT Id FROM Account  ';
        $query2 = 'select id from account';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('generateCacheKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($cache, $query1);
        $key2 = $method->invoke($cache, $query2);

        // Should be the same after normalization
        expect($key1)->toBe($key2);
    });
});

describe('object extraction from queries', function () {
    it('extracts object name from simple query', function () {
        $cache = new QueryCache;
        $query = "SELECT Id, Name FROM Account WHERE Industry = 'Tech'";

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('extractObjectFromQuery');
        $method->setAccessible(true);

        $object = $method->invoke($cache, $query);

        expect($object)->toBe('Account');
    });

    it('extracts object name case-insensitively', function () {
        $cache = new QueryCache;
        $query = "select id from account where name = 'Test'";

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('extractObjectFromQuery');
        $method->setAccessible(true);

        $object = $method->invoke($cache, $query);

        expect($object)->toBe('account');
    });

    it('handles custom objects with underscores', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Custom_Object__c WHERE Active = true';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('extractObjectFromQuery');
        $method->setAccessible(true);

        $object = $method->invoke($cache, $query);

        expect($object)->toBe('Custom_Object__c');
    });
});

describe('aggregate query detection', function () {
    it('detects COUNT queries', function () {
        $cache = new QueryCache;
        $query = 'SELECT COUNT(Id) FROM Account';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('isAggregateQuery');
        $method->setAccessible(true);

        $isAggregate = $method->invoke($cache, $query);

        expect($isAggregate)->toBeTrue();
    });

    it('detects SUM queries', function () {
        $cache = new QueryCache;
        $query = 'SELECT SUM(Amount) FROM Opportunity';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('isAggregateQuery');
        $method->setAccessible(true);

        $isAggregate = $method->invoke($cache, $query);

        expect($isAggregate)->toBeTrue();
    });

    it('detects AVG queries', function () {
        $cache = new QueryCache;
        $query = 'SELECT AVG(Amount) FROM Opportunity';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('isAggregateQuery');
        $method->setAccessible(true);

        $isAggregate = $method->invoke($cache, $query);

        expect($isAggregate)->toBeTrue();
    });

    it('does not detect regular queries as aggregate', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id, Name FROM Account';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('isAggregateQuery');
        $method->setAccessible(true);

        $isAggregate = $method->invoke($cache, $query);

        expect($isAggregate)->toBeFalse();
    });
});

describe('TTL resolution', function () {
    it('uses default TTL when no overrides', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Contact';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('resolveTtl');
        $method->setAccessible(true);

        $ttl = $method->invoke($cache, $query, []);

        expect($ttl)->toBe(3600); // Default TTL
    });

    it('uses per-object TTL override', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Account';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('resolveTtl');
        $method->setAccessible(true);

        $ttl = $method->invoke($cache, $query, []);

        expect($ttl)->toBe(7200); // Account override
    });

    it('uses explicit TTL from options', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Account';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('resolveTtl');
        $method->setAccessible(true);

        $ttl = $method->invoke($cache, $query, ['ttl' => 600]);

        expect($ttl)->toBe(600); // Explicit option takes precedence
    });
});

describe('cache tags resolution', function () {
    it('includes global Salesforce tag', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Account';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('resolveTags');
        $method->setAccessible(true);

        $tags = $method->invoke($cache, $query, []);

        expect($tags)->toContain('salesforce_queries');
    });

    it('includes object-specific tag', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Account';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('resolveTags');
        $method->setAccessible(true);

        $tags = $method->invoke($cache, $query, []);

        expect($tags)->toContain('sf_object_Account');
    });

    it('includes custom tags from options', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Account';

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('resolveTags');
        $method->setAccessible(true);

        $tags = $method->invoke($cache, $query, ['tags' => ['custom-tag']]);

        expect($tags)->toContain('custom-tag');
        expect($tags)->toContain('salesforce_queries');
        expect($tags)->toContain('sf_object_Account');
    });
});

describe('cache remember functionality', function () {
    it('executes callback on cache miss', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Account';
        $executed = false;

        $result = $cache->remember($query, function () use (&$executed) {
            $executed = true;
            return ['record1', 'record2'];
        });

        expect($executed)->toBeTrue();
        expect($result)->toBe(['record1', 'record2']);
    });

    it('returns cached result on cache hit', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Account';
        $executionCount = 0;

        // First call - cache miss
        $cache->remember($query, function () use (&$executionCount) {
            $executionCount++;
            return ['record1'];
        });

        // Second call - cache hit
        $result = $cache->remember($query, function () use (&$executionCount) {
            $executionCount++;
            return ['record1'];
        });

        expect($executionCount)->toBe(1); // Callback executed only once
        expect($result)->toBe(['record1']);
    });

    it('skips cache when skip_cache option is true', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Account';
        $executionCount = 0;

        // First call with skip_cache
        $cache->remember($query, function () use (&$executionCount) {
            $executionCount++;
            return ['record1'];
        }, ['skip_cache' => true]);

        // Second call with skip_cache
        $cache->remember($query, function () use (&$executionCount) {
            $executionCount++;
            return ['record1'];
        }, ['skip_cache' => true]);

        expect($executionCount)->toBe(2); // Callback executed both times
    });

    it('refreshes cache when refresh_cache option is true', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Account';
        $executionCount = 0;

        // First call - cache miss
        $cache->remember($query, function () use (&$executionCount) {
            $executionCount++;
            return ['old-data'];
        });

        // Second call with refresh - should execute callback
        $result = $cache->remember($query, function () use (&$executionCount) {
            $executionCount++;
            return ['new-data'];
        }, ['refresh_cache' => true]);

        expect($executionCount)->toBe(2);
        expect($result)->toBe(['new-data']);
    });
});

describe('cache invalidation', function () {
    it('forgets specific query cache', function () {
        $cache = new QueryCache;
        $query = 'SELECT Id FROM Account';

        // Cache a result
        $cache->remember($query, fn () => ['record1']);

        // Forget it
        $cache->forget($query);

        // Verify it was forgotten by checking if callback executes again
        $executed = false;
        $cache->remember($query, function () use (&$executed) {
            $executed = true;
            return ['record2'];
        });

        expect($executed)->toBeTrue();
    });

    it('flushes all cache for specific object', function () {
        $cache = new QueryCache;

        // Cache multiple Account queries
        $cache->remember("SELECT Id FROM Account WHERE Industry = 'Tech'", fn () => ['tech1']);
        $cache->remember("SELECT Id FROM Account WHERE Industry = 'Finance'", fn () => ['finance1']);
        $cache->remember('SELECT Id FROM Contact', fn () => ['contact1']);

        // Flush Account cache
        $cache->flushObject('Account');

        // Verify Account queries were flushed but Contact wasn't
        $accountExecuted = false;
        $cache->remember("SELECT Id FROM Account WHERE Industry = 'Tech'", function () use (&$accountExecuted) {
            $accountExecuted = true;
            return ['tech2'];
        });

        $contactExecuted = false;
        $cache->remember('SELECT Id FROM Contact', function () use (&$contactExecuted) {
            $contactExecuted = true;
            return ['contact2'];
        });

        expect($accountExecuted)->toBeTrue(); // Account was flushed, callback executed
        expect($contactExecuted)->toBeFalse(); // Contact still cached
    });
});

describe('cache statistics', function () {
    it('tracks cache statistics when analytics enabled', function () {
        config(['eloquent-salesforce-objects.enable_query_log' => true]);

        $cache = new QueryCache;
        $cache->resetStatistics();

        $query = 'SELECT Id FROM Account';

        // Cache miss
        $cache->remember($query, fn () => ['record1']);

        // Cache hit
        $cache->remember($query, fn () => ['record1']);
        $cache->remember($query, fn () => ['record1']);

        $stats = $cache->getStatistics();

        expect($stats['enabled'])->toBeTrue();
        expect($stats['hits'])->toBeGreaterThanOrEqual(2);
        expect($stats['misses'])->toBeGreaterThanOrEqual(1);
    });

    it('returns disabled message when analytics not enabled', function () {
        config(['eloquent-salesforce-objects.enable_query_log' => false]);

        $cache = new QueryCache;
        $stats = $cache->getStatistics();

        expect($stats['enabled'])->toBeFalse();
        expect($stats)->toHaveKey('message');
    });
});
