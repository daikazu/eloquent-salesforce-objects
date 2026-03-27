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

function allAccountFields(): array
{
    return [
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
            ['name' => 'CustomField__c'],
            ['name' => 'AnotherField__c'],
            ['name' => 'CreatedDate'],
            ['name' => 'LastModifiedDate'],
            ['name' => 'IsDeleted'],
        ],
    ];
}

describe('defaultColumns', function () {
    it('uses defaultColumns when fetching records', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(allAccountFields());

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
        Forrest::shouldReceive('describe')->with('Account')->andReturn(allAccountFields());

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
    });

    it('allColumns() ignores defaultColumns and fetches all fields', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(allAccountFields());

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                // Should include fields NOT in defaultColumns
                return str_contains($query, 'CustomField__c') &&
                       str_contains($query, 'AnotherField__c');
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        Account::allColumns()->get();
    });

    it('explicit select() overrides defaultColumns', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(allAccountFields());

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
    });

    it('model without defaultColumns uses all fields', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Contact')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'FirstName'],
                ['name' => 'LastName'],
                ['name' => 'Email'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                // Should include all fields since no defaultColumns
                return str_contains($query, 'FirstName') &&
                       str_contains($query, 'LastName') &&
                       str_contains($query, 'Email') &&
                       str_contains($query, 'from Contact');
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        // Use the Contact example which has no defaultColumns
        \Daikazu\EloquentSalesforceObjects\Examples\Contact::get();
    });
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
