<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects;

use Daikazu\EloquentSalesforceObjects\Commands\EloquentSalesforceObjectsCommand;
use Daikazu\EloquentSalesforceObjects\Console\Commands\ClearSalesforceCache;
use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Http\Middleware\ValidateSalesforceWebhook;
use Daikazu\EloquentSalesforceObjects\Support\AuthenticationManager;
use Daikazu\EloquentSalesforceObjects\Support\QueryCache;
use Daikazu\EloquentSalesforceObjects\Support\ResponseParser;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Illuminate\Routing\Router;
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
                ClearSalesforceCache::class,
            ]);
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(AuthenticationManager::class, fn ($app): \Daikazu\EloquentSalesforceObjects\Support\AuthenticationManager => new AuthenticationManager);

        $this->app->singleton(ResponseParser::class, fn ($app): \Daikazu\EloquentSalesforceObjects\Support\ResponseParser => new ResponseParser);

        $this->app->singleton(QueryCache::class, fn ($app): \Daikazu\EloquentSalesforceObjects\Support\QueryCache => new QueryCache);

        $this->app->singleton(SalesforceAdapter::class, fn ($app): \Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter => new SalesforceAdapter(
            $app->make(AuthenticationManager::class)
        ));

        $this->app->bind(AdapterInterface::class, SalesforceAdapter::class);
    }

    public function bootingPackage(): void
    {
        // Always register webhook routes (controller will check if enabled)
        $this->registerWebhookRoutes();

        // Register middleware alias
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('salesforce.webhook', ValidateSalesforceWebhook::class);
    }

    protected function registerWebhookRoutes(): void
    {
        $router = $this->app->make(Router::class);

        $router->group([
            'prefix'     => config('eloquent-salesforce-objects.webhook_route_prefix', 'api/salesforce/webhooks'),
            'middleware' => ['api'],
        ], function (Router $router): void {
            // Health check endpoint (no authentication required)
            $router->get('/health', [
                'uses' => '\Daikazu\EloquentSalesforceObjects\Http\Controllers\SalesforceWebhookController@healthCheck',
                'as'   => 'salesforce.webhook.health',
            ]);

            // CDC webhook endpoint (with authentication)
            $router->post('/cdc', [
                'uses'       => '\Daikazu\EloquentSalesforceObjects\Http\Controllers\SalesforceWebhookController@handle',
                'middleware' => 'salesforce.webhook',
                'as'         => 'salesforce.webhook.cdc',
            ]);
        });
    }
}
