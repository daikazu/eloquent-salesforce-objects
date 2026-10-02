<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects;

use Daikazu\EloquentSalesforceObjects\Commands\MakeSalesforceModelCommand;
use Daikazu\EloquentSalesforceObjects\Commands\SalesforceTestConnectionCommand;
use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Support\AuthenticationManager;
use Daikazu\EloquentSalesforceObjects\Support\ResponseParser;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class EloquentSalesforceObjectsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('eloquent-salesforce-objects')
            ->hasConfigFile()
            ->hasCommands([
                MakeSalesforceModelCommand::class,
                SalesforceTestConnectionCommand::class,
            ]);
    }

    public function bootingPackage(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../stubs/salesforce-model.stub' => base_path('stubs/salesforce-model.stub'),
            ], 'salesforce-stubs');
        }
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(AuthenticationManager::class, fn ($app): AuthenticationManager => new AuthenticationManager);

        $this->app->singleton(ResponseParser::class, fn ($app): ResponseParser => new ResponseParser);

        $this->app->singleton(SalesforceAdapter::class, fn ($app): SalesforceAdapter => new SalesforceAdapter(
            $app->make(AuthenticationManager::class)
        ));

        // Resolve the interface to the same singleton so reads, writes and batches
        // share one adapter, and rebinding the interface swaps it everywhere.
        $this->app->singleton(AdapterInterface::class, fn ($app): AdapterInterface => $app->make(SalesforceAdapter::class));
    }
}
