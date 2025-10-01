<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Console\Commands;

use Daikazu\EloquentSalesforceObjects\Support\QueryCache;
use Illuminate\Console\Command;

class ClearSalesforceCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'salesforce:cache-clear
                            {object? : Specific Salesforce object to clear (e.g., Account)}
                            {--stats : Show cache statistics before clearing}
                            {--all : Clear all Salesforce cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear Salesforce query cache';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queryCache = new QueryCache;

        if (! $queryCache->isEnabled()) {
            $this->warn('Salesforce query cache is disabled.');
            $this->info('Enable it in config/eloquent-salesforce-objects.php or set SALESFORCE_QUERY_CACHE=true');
            return self::SUCCESS;
        }

        // Show statistics if requested
        if ($this->option('stats')) {
            $this->displayStatistics($queryCache);
            $this->newLine();
        }

        $object = $this->argument('object');
        $clearAll = $this->option('all');

        if ($clearAll) {
            $this->clearAllCache($queryCache);
        } elseif ($object) {
            $this->clearObjectCache($queryCache, $object);
        } else {
            // Interactive mode
            $this->interactiveMode($queryCache);
        }

        return self::SUCCESS;
    }

    /**
     * Display cache statistics
     */
    protected function displayStatistics(QueryCache $queryCache): void
    {
        $stats = $queryCache->getStatistics();

        if (! $stats['enabled']) {
            $this->info('Cache analytics not enabled. Set enable_query_log to true in config.');
            return;
        }

        $this->info('Salesforce Query Cache Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Cache Hits', number_format($stats['hits'])],
                ['Cache Misses', number_format($stats['misses'])],
                ['Total Queries', number_format($stats['total'])],
                ['Hit Rate', $stats['hit_rate_percentage'] . '%'],
            ]
        );
    }

    /**
     * Clear all Salesforce cache
     */
    protected function clearAllCache(QueryCache $queryCache): void
    {
        if (! $this->confirm('Are you sure you want to clear ALL Salesforce query cache?', false)) {
            $this->info('Cache clear cancelled.');
            return;
        }

        $this->info('Clearing all Salesforce query cache...');
        $queryCache->flushAll();
        $queryCache->resetStatistics();

        $this->info('✓ All Salesforce query cache cleared successfully!');
    }

    /**
     * Clear cache for specific object
     */
    protected function clearObjectCache(QueryCache $queryCache, string $object): void
    {
        $this->info("Clearing cache for Salesforce object: {$object}");
        $queryCache->flushObject($object);

        $this->info("✓ Cache cleared for {$object}!");
    }

    /**
     * Interactive mode for cache clearing
     */
    protected function interactiveMode(QueryCache $queryCache): void
    {
        $choice = $this->choice(
            'What would you like to clear?',
            [
                'all'    => 'All Salesforce query cache',
                'object' => 'Specific object cache',
                'stats'  => 'Just show statistics',
                'cancel' => 'Cancel',
            ],
            'cancel'
        );

        switch ($choice) {
            case 'all':
            case 'All Salesforce query cache':
                $this->clearAllCache($queryCache);
                break;

            case 'object':
            case 'Specific object cache':
                $object = $this->ask('Enter Salesforce object name (e.g., Account, Opportunity)');
                if ($object) {
                    $this->clearObjectCache($queryCache, $object);
                } else {
                    $this->error('No object name provided');
                }
                break;

            case 'stats':
            case 'Just show statistics':
                $this->displayStatistics($queryCache);
                break;

            default:
                $this->info('Cache clear cancelled.');
                break;
        }
    }
}
