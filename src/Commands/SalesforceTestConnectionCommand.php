<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Commands;

use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Illuminate\Console\Command;
use Throwable;

class SalesforceTestConnectionCommand extends Command
{
    protected $signature = 'salesforce:test';

    protected $description = 'Test the Salesforce API connection';

    public function handle(SalesforceAdapter $adapter): int
    {
        $this->info('Testing Salesforce connection...');
        $this->newLine();

        // Test authentication
        try {
            $instanceUrl = $adapter->getInstanceUrl();
            $this->line("  Instance URL: {$instanceUrl}");
        } catch (Throwable $e) {
            $this->error("Authentication failed: {$e->getMessage()}");
            $this->newLine();
            $this->line('Check your Forrest configuration and credentials.');
            $this->line('See: https://github.com/omniphx/forrest#setting-up-connected-app');

            return self::FAILURE;
        }

        // Test API access with a describe call
        try {
            $global = $adapter->describeGlobal();
            $objectCount = count($global['sobjects'] ?? []);
            $this->line("  API version: " . config('forrest.version', 'default'));
            $this->line("  Objects available: {$objectCount}");
        } catch (Throwable $e) {
            $this->error("API call failed: {$e->getMessage()}");
            $this->newLine();
            $this->line('Authentication succeeded but the API call failed.');
            $this->line('Check your user permissions and API access settings.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Connection successful!');

        return self::SUCCESS;
    }
}
