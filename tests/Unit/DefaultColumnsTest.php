<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    // Mock the Forrest service in the container
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);
});

afterEach(function () {
    Mockery::close();
});

describe('defaultColumns', function () {
    it('uses defaultColumns when fetching records', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock the describe call to return all fields
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'Name'],
                    ['name' => 'Type'],
                    ['name' => 'Industry'],
                    ['name' => 'Website'],
                    ['name' => 'Phone'],
                    ['name' => 'BillingStreet'],
                    ['name' => 'BillingCity'],
                    ['name' => 'BillingState'],
                    ['name' => 'BillingPostalCode'],
                    ['name' => 'BillingCountry'],
                    ['name' => 'AnnualRevenue'],
                    ['name' => 'NumberOfEmployees'],
                    ['name' => 'OwnerId'],
                    ['name' => 'CreatedDate'],
                    ['name' => 'LastModifiedDate'],
                    ['name' => 'IsDeleted'],
                ],
            ]);

        // Mock the query - it should only request the default columns
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                // Query should include default columns plus Id, timestamps, IsDeleted
                return str_contains($query, 'Name') &&
                       str_contains($query, 'Type') &&
                       str_contains($query, 'Id') &&
                       str_contains($query, 'CreatedDate') &&
                       str_contains($query, 'LastModifiedDate') &&
                       str_contains($query, 'IsDeleted');
            }))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'   => '001xx000003DGb2AAG',
                        'Name' => 'Acme Corp',
                        'Type' => 'Customer',
                    ],
                ],
            ]);

        $accounts = Account::get();

        expect($accounts)->toHaveCount(1);
    });

    it('automatically includes Id in defaultColumns', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('post')->andReturn([
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
            ->with(Mockery::on(function ($query) {
                // Id should always be included even if not in defaultColumns
                return str_contains($query, 'Id');
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        Account::get();
    })->skip('Needs proper describe mock setup');

    it('allColumns() ignores defaultColumns and fetches all fields', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock the describe call
        Forrest::shouldReceive('post')
            ->with('versionUrl/sobjects/Account/describe', Mockery::any())
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'Name'],
                    ['name' => 'Type'],
                    ['name' => 'CustomField__c'],
                    ['name' => 'AnotherField__c'],
                    ['name' => 'CreatedDate'],
                    ['name' => 'LastModifiedDate'],
                    ['name' => 'IsDeleted'],
                ],
            ]);

        // When using allColumns(), it should request * which gets resolved to all fields
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                // Should include fields NOT in defaultColumns
                return str_contains($query, 'CustomField__c') ||
                       str_contains($query, 'AnotherField__c');
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        Account::allColumns()->get();
    })->skip('Needs proper describe mock setup');

    it('explicit select() overrides defaultColumns', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('post')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Email'],
            ],
        ]);

        // When explicitly selecting columns, defaultColumns should be ignored
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                // Should only have Id and Name (what we explicitly selected)
                return str_contains($query, 'select Id, Name from');
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        Account::select(['Id', 'Name'])->get();
    })->skip('Needs proper column handling verification');

    it('model without defaultColumns uses all fields', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Create a model without defaultColumns
        $model = new class extends SalesforceModel
        {
            protected $table = 'Contact';
            protected ?array $defaultColumns = null; // Explicitly null
        };

        Forrest::shouldReceive('post')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'FirstName'],
                    ['name' => 'LastName'],
                    ['name' => 'Email'],
                ],
            ]);

        // Should query for all fields
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $model::get();
    })->skip('Needs dynamic model instantiation fix');
});

describe('getDefaultColumns', function () {
    it('returns defaultColumns array when set', function () {
        $account = new Account;
        $defaults = $account->getDefaultColumns();

        expect($defaults)->toBeArray();
        expect($defaults)->toContain('Name');
        expect($defaults)->toContain('Type');
    });

    it('returns null when defaultColumns not set', function () {
        $model = new class extends SalesforceModel
        {
            protected $table = 'TestObject';
            // defaultColumns not defined, defaults to null
        };

        expect($model->getDefaultColumns())->toBeNull();
    });
});
