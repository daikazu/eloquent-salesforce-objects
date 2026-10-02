<?php

use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

beforeEach(function () {
    $adapter = Mockery::mock(SalesforceAdapter::class);
    $adapter->shouldReceive('getInstanceUrl')->andReturn('https://example.my.salesforce.com');
    $adapter->shouldReceive('describeGlobal')->andReturn(['sobjects' => [['name' => 'Account']]]);

    $this->app->instance(SalesforceAdapter::class, $adapter);
});

afterEach(function () {
    Mockery::close();
});

it('warns when authenticating with SOAP API login()', function () {
    config()->set('forrest.authentication', 'UserPasswordSoap');

    $this->artisan('salesforce:test')
        ->expectsOutputToContain('SOAP API login()')
        ->assertSuccessful();
});

it('does not warn for OAuth flows', function () {
    config()->set('forrest.authentication', 'ClientCredentials');

    $this->artisan('salesforce:test')
        ->doesntExpectOutputToContain('SOAP API login()')
        ->expectsOutputToContain('Connection successful!')
        ->assertSuccessful();
});
