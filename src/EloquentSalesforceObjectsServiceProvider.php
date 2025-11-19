<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects;

use Daikazu\EloquentSalesforceObjects\Commands\EloquentSalesforceObjectsCommand;
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
                EloquentSalesforceObjectsCommand::class,
            ]);
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(AuthenticationManager::class, fn ($app): \Daikazu\EloquentSalesforceObjects\Support\AuthenticationManager => new AuthenticationManager);

        $this->app->singleton(ResponseParser::class, fn ($app): \Daikazu\EloquentSalesforceObjects\Support\ResponseParser => new ResponseParser);

        $this->app->singleton(SalesforceAdapter::class, fn ($app): \Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter => new SalesforceAdapter(
            $app->make(AuthenticationManager::class)
        ));

        $this->app->bind(AdapterInterface::class, SalesforceAdapter::class);
    }
}
