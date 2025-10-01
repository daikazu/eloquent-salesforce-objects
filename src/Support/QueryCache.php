<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Manages caching of Salesforce query results with smart invalidation
 *
 * Features:
 * - Query fingerprinting for cache keys
 * - Per-object TTL configuration
 * - Tag-based cache organization
 * - Cache analytics (hit/miss tracking)
 * - Flexible invalidation strategies
 */
class QueryCache
{
    protected bool $enabled;
    protected int $defaultTtl;
    protected array $ttlOverrides;
    protected ?string $driver;
    protected bool $trackAnalytics;
    protected string $invalidationStrategy;

    public function __construct()
    {
        $config = config('eloquent-salesforce-objects.query_cache', []);

        $this->enabled = $config['enabled'] ?? true;
        $this->defaultTtl = $config['default_ttl'] ?? 3600;
        $this->ttlOverrides = $config['ttl_overrides'] ?? [];
        $this->driver = $config['driver'] ?? null;
        $this->trackAnalytics = config('eloquent-salesforce-objects.enable_query_log', false);
        $this->invalidationStrategy = $config['invalidation_strategy'] ?? 'record'; // 'record' or 'object'
    }

    /**
     * Check if query caching is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get cached query result or execute callback and cache result
     *
     * @param  string  $query  SOQL query string
     * @param  callable  $callback  Function to execute if cache miss
     * @param  array  $options  Cache options (ttl, tags, skip_cache, refresh_cache)
     * @return array Query results
     */
    public function remember(string $query, callable $callback, array $options = []): array
    {
        // Check if caching is disabled globally
        if (! $this->enabled) {
            return $callback();
        }

        // Check if caching is disabled for this specific query
        if ($options['skip_cache'] ?? false) {
            return $callback();
        }

        // Never cache aggregate queries (COUNT, SUM, AVG, MIN, MAX)
        if ($this->isAggregateQuery($query)) {
            return $callback();
        }

        $cacheKey = $this->generateCacheKey($query);
        $ttl = $this->resolveTtl($query, $options);
        $tags = $this->resolveTags($query, $options);
        $store = $this->getCacheStore();

        // Force refresh cache if requested
        if ($options['refresh_cache'] ?? false) {
            $result = $callback();
            $this->put($cacheKey, $result, $ttl, $tags);
            $this->trackRecordIds($cacheKey, $result, $query, $ttl);
            $this->trackCacheMiss($query);
            return $result;
        }

        // Check if cache exists BEFORE calling remember
        $cacheExists = $tags !== [] && $this->supportsTags($store)
            ? $store->tags($tags)->has($cacheKey)
            : $store->has($cacheKey);

        // Use cache tags if available
        if ($tags !== [] && $this->supportsTags($store)) {
            $cached = $store->tags($tags)->remember($cacheKey, $ttl, function () use ($callback, $query, $cacheKey, $ttl) {
                $this->trackCacheMiss($query);
                $result = $callback();
                $this->trackRecordIds($cacheKey, $result, $query, $ttl);
                return $result;
            });
        } else {
            $cached = $store->remember($cacheKey, $ttl, function () use ($callback, $query, $cacheKey, $ttl) {
                $this->trackCacheMiss($query);
                $result = $callback();
                $this->trackRecordIds($cacheKey, $result, $query, $ttl);
                return $result;
            });
        }

        // Track cache hit if value was already cached (checked before remember)
        if ($cacheExists) {
            $this->trackCacheHit($query);
        }

        return $cached;
    }

    /**
     * Store value in cache
     *
     * @param  string  $key  Cache key
     * @param  mixed  $value  Value to cache
     * @param  int  $ttl  Time to live in seconds
     * @param  array  $tags  Cache tags
     */
    public function put(string $key, mixed $value, int $ttl, array $tags = []): void
    {
        $store = $this->getCacheStore();

        if ($tags !== [] && $this->supportsTags($store)) {
            $store->tags($tags)->put($key, $value, $ttl);
        } else {
            $store->put($key, $value, $ttl);
        }
    }

    /**
     * Forget cached value
     *
     * @param  string  $query  SOQL query string
     */
    public function forget(string $query): void
    {
        $cacheKey = $this->generateCacheKey($query);
        $tags = $this->resolveTags($query, []);
        $store = $this->getCacheStore();

        // Forget with tags if supported
        if ($tags !== [] && $this->supportsTags($store)) {
            $store->tags($tags)->forget($cacheKey);
        } else {
            $store->forget($cacheKey);
        }
    }

    /**
     * Flush cache for specific Salesforce object(s)
     *
     * @param  string|array  $objects  Object name(s) to flush
     */
    public function flushObject(string | array $objects): void
    {
        $objects = (array) $objects;
        $store = $this->getCacheStore();

        if ($this->supportsTags($store)) {
            foreach ($objects as $object) {
                $tag = $this->generateObjectTag($object);
                $store->tags([$tag])->flush();

                if ($this->trackAnalytics) {
                    Log::info("Flushed Salesforce cache for object: {$object}");
                }
            }
        } else {
            // Fallback: clear all cache if tags not supported
            Log::warning('Cache driver does not support tags. Consider using Redis or Memcached for better cache invalidation.');
        }
    }

    /**
     * Flush all Salesforce query cache
     */
    public function flushAll(): void
    {
        $store = $this->getCacheStore();

        if ($this->supportsTags($store)) {
            $store->tags(['salesforce_queries'])->flush();
        } else {
            // This is destructive - only use if no tags support
            Log::warning('Flushing entire cache store. Consider using a cache driver with tag support.');
            $store->flush();
        }

        if ($this->trackAnalytics) {
            Log::info('Flushed all Salesforce query cache');
        }
    }

    /**
     * Invalidate cache for a specific record
     *
     * @param  string  $object  Salesforce object name
     * @param  string  $recordId  Record ID
     */
    public function invalidateRecord(string $object, string $recordId): void
    {
        // Pattern: any query that might return this record
        // For now, just flush the entire object cache
        // Future: implement query pattern matching
        $this->flushObject($object);

        if ($this->trackAnalytics) {
            Log::info("Invalidated cache for {$object} record: {$recordId}");
        }
    }

    /**
     * Generate cache key from query
     *
     * @param  string  $query  SOQL query string
     * @return string Cache key
     */
    protected function generateCacheKey(string $query): string
    {
        // Normalize query (trim, lowercase for consistent hashing)
        $normalized = trim(strtolower($query));

        // Generate hash
        $hash = hash('xxh3', $normalized);

        return "sf_query_{$hash}";
    }

    /**
     * Generate cache tag for Salesforce object
     *
     * @param  string  $object  Object name
     * @return string Cache tag
     */
    protected function generateObjectTag(string $object): string
    {
        return "sf_object_{$object}";
    }

    /**
     * Resolve TTL for query
     *
     * @param  string  $query  SOQL query
     * @param  array  $options  Cache options
     * @return int TTL in seconds
     */
    protected function resolveTtl(string $query, array $options): int
    {
        // Option 1: Explicit TTL in options
        if (isset($options['ttl'])) {
            return (int) $options['ttl'];
        }

        // Option 2: Per-object TTL override
        $object = $this->extractObjectFromQuery($query);
        if ($object && isset($this->ttlOverrides[$object])) {
            return $this->ttlOverrides[$object];
        }

        // Option 3: Default TTL
        return $this->defaultTtl;
    }

    /**
     * Resolve cache tags for query
     *
     * @param  string  $query  SOQL query
     * @param  array  $options  Cache options
     * @return array Cache tags
     */
    protected function resolveTags(string $query, array $options): array
    {
        $tags = $options['tags'] ?? [];

        // Add global Salesforce tag
        $tags[] = 'salesforce_queries';

        // Add object-specific tag
        $object = $this->extractObjectFromQuery($query);
        if ($object !== null && $object !== '' && $object !== '0') {
            $tags[] = $this->generateObjectTag($object);
        }

        return array_unique($tags);
    }

    /**
     * Extract Salesforce object name from SOQL query
     *
     * @param  string  $query  SOQL query
     * @return string|null Object name or null
     */
    protected function extractObjectFromQuery(string $query): ?string
    {
        // Match: FROM ObjectName or FROM "ObjectName" or from objectname (case-insensitive)
        if (preg_match('/\bfrom\s+["\']?([a-z0-9_]+)["\']?/i', $query, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if query is an aggregate query
     *
     * @param  string  $query  SOQL query
     */
    protected function isAggregateQuery(string $query): bool
    {
        $query = strtoupper($query);
        return stripos($query, 'COUNT(') !== false ||
               stripos($query, 'SUM(') !== false ||
               stripos($query, 'AVG(') !== false ||
               stripos($query, 'MIN(') !== false ||
               stripos($query, 'MAX(') !== false;
    }

    /**
     * Get cache store instance
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    protected function getCacheStore()
    {
        return $this->driver !== null && $this->driver !== '' && $this->driver !== '0' ? Cache::store($this->driver) : Cache::store();
    }

    /**
     * Check if cache store supports tagging
     *
     * @param  mixed  $store  Cache store instance
     */
    protected function supportsTags($store): bool
    {
        return method_exists($store, 'tags');
    }

    /**
     * Track cache hit for analytics
     *
     * @param  string  $query  SOQL query
     */
    protected function trackCacheHit(string $query): void
    {
        if (! $this->trackAnalytics) {
            return;
        }

        Cache::increment('sf_cache_hits');

        Log::debug('Salesforce cache hit', [
            'query' => substr($query, 0, 100), // Log first 100 chars
        ]);
    }

    /**
     * Track cache miss for analytics
     *
     * @param  string  $query  SOQL query
     */
    protected function trackCacheMiss(string $query): void
    {
        if (! $this->trackAnalytics) {
            return;
        }

        Cache::increment('sf_cache_misses');

        Log::debug('Salesforce cache miss', [
            'query' => substr($query, 0, 100),
        ]);
    }

    /**
     * Get cache statistics
     *
     * @return array Cache hit/miss statistics
     */
    public function getStatistics(): array
    {
        if (! $this->trackAnalytics) {
            return [
                'enabled' => false,
                'message' => 'Cache analytics not enabled. Set enable_query_log to true.',
            ];
        }

        $hits = (int) Cache::get('sf_cache_hits', 0);
        $misses = (int) Cache::get('sf_cache_misses', 0);
        $total = $hits + $misses;
        $hitRate = $total > 0 ? round(($hits / $total) * 100, 2) : 0;

        return [
            'enabled'             => true,
            'hits'                => $hits,
            'misses'              => $misses,
            'total'               => $total,
            'hit_rate_percentage' => $hitRate,
        ];
    }

    /**
     * Reset cache statistics
     */
    public function resetStatistics(): void
    {
        Cache::forget('sf_cache_hits');
        Cache::forget('sf_cache_misses');
    }

    /**
     * Track record IDs from query results for record-level invalidation
     *
     * @param  string  $cacheKey  Cache key for the query
     * @param  array  $results  Query results
     * @param  string  $query  SOQL query string
     * @param  int  $ttl  Time to live in seconds
     */
    protected function trackRecordIds(string $cacheKey, array $results, string $query, int $ttl): void
    {
        // Only track if using record-level invalidation
        if ($this->invalidationStrategy !== 'record') {
            return;
        }

        // Don't track aggregate queries
        if ($this->isAggregateQuery($query)) {
            return;
        }

        // Extract record IDs from results
        $recordIds = $this->extractRecordIds($results);

        if ($recordIds === []) {
            return;
        }

        $object = $this->extractObjectFromQuery($query);
        if (in_array($object, [null, '', '0'], true)) {
            return;
        }

        $store = $this->getCacheStore();

        // For each record ID, store which cache keys contain it
        foreach ($recordIds as $recordId) {
            $recordCacheKey = $this->generateRecordCacheKey($object, $recordId);

            // Get existing cache keys for this record
            $existingKeys = $store->get($recordCacheKey, []);

            // Add this cache key if not already tracked
            if (! in_array($cacheKey, $existingKeys)) {
                $existingKeys[] = $cacheKey;
            }

            // Store updated list with same TTL as query cache
            $store->put($recordCacheKey, $existingKeys, $ttl);
        }

        if ($this->trackAnalytics) {
            Log::debug('Tracked record IDs for cache key', [
                'cache_key'    => $cacheKey,
                'object'       => $object,
                'record_count' => count($recordIds),
            ]);
        }
    }

    /**
     * Extract record IDs from query results
     *
     * @param  array  $results  Query results
     * @return array Array of record IDs
     */
    protected function extractRecordIds(array $results): array
    {
        $recordIds = [];

        // Handle different result formats
        if ($results === []) {
            return $recordIds;
        }

        // If results is a collection of records
        foreach ($results as $record) {
            if (is_array($record) && isset($record['Id'])) {
                $recordIds[] = $record['Id'];
            } elseif (is_object($record) && isset($record->Id)) {
                $recordIds[] = $record->Id;
            }
        }

        return array_unique($recordIds);
    }

    /**
     * Generate cache key for record-to-cachekey mapping
     *
     * @param  string  $object  Salesforce object name
     * @param  string  $recordId  Record ID
     * @return string Cache key
     */
    protected function generateRecordCacheKey(string $object, string $recordId): string
    {
        return "sf_record_{$object}_{$recordId}";
    }

    /**
     * Invalidate cache by specific record IDs (surgical invalidation)
     *
     * @param  string  $object  Salesforce object name
     * @param  array  $recordIds  Array of record IDs that changed
     */
    public function invalidateByRecordIds(string $object, array $recordIds): void
    {
        if ($recordIds === []) {
            return;
        }

        $store = $this->getCacheStore();
        $invalidatedKeys = [];

        foreach ($recordIds as $recordId) {
            $recordCacheKey = $this->generateRecordCacheKey($object, $recordId);

            // Get all cache keys that contain this record
            $cacheKeys = $store->get($recordCacheKey, []);

            if (empty($cacheKeys)) {
                continue;
            }

            // Invalidate each cache key
            foreach ($cacheKeys as $cacheKey) {
                if (! in_array($cacheKey, $invalidatedKeys)) {
                    $store->forget($cacheKey);
                    $invalidatedKeys[] = $cacheKey;
                }
            }

            // Clean up the record-to-cachekey mapping
            $store->forget($recordCacheKey);
        }

        if ($this->trackAnalytics && $invalidatedKeys !== []) {
            Log::info('Invalidated cache by record IDs', [
                'object'                 => $object,
                'record_ids'             => $recordIds,
                'cache_keys_invalidated' => count($invalidatedKeys),
            ]);
        }
    }

    /**
     * Get the current invalidation strategy
     *
     * @return string 'record' or 'object'
     */
    public function getInvalidationStrategy(): string
    {
        return $this->invalidationStrategy;
    }
}
