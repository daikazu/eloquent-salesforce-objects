<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Examples\Contact;
use Daikazu\EloquentSalesforceObjects\Examples\Opportunity;
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

describe('hasMany relationship', function () {
    it('lazy loads related records with correct foreign key', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe for Account
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'Name'],
                    ['name' => 'CreatedDate'],
                    ['name' => 'LastModifiedDate'],
                    ['name' => 'IsDeleted'],
                ],
            ]);

        // Mock the initial Account query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'select') && str_contains($query, 'from Account');
            }))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '001xx000003DGb2AAG',
                        'Name'       => 'Acme Corp',
                        'attributes' => ['type' => 'Account'],
                    ],
                ],
            ]);

        // Mock describe for Opportunity
        Forrest::shouldReceive('describe')
            ->with('Opportunity')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'Name'],
                    ['name' => 'AccountId'],
                    ['name' => 'CreatedDate'],
                    ['name' => 'LastModifiedDate'],
                    ['name' => 'IsDeleted'],
                ],
            ]);

        // Mock the lazy load query for opportunities - should use AccountId
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                // Verify it uses AccountId (PascalCase) not account_id
                return str_contains($query, 'AccountId = \'001xx000003DGb2AAG\'') &&
                       str_contains($query, 'from Opportunity');
            }))
            ->andReturn([
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '006xx000001abc1',
                        'Name'       => 'Big Deal',
                        'AccountId'  => '001xx000003DGb2AAG',
                        'attributes' => ['type' => 'Opportunity'],
                    ],
                    [
                        'Id'         => '006xx000001abc2',
                        'Name'       => 'Bigger Deal',
                        'AccountId'  => '001xx000003DGb2AAG',
                        'attributes' => ['type' => 'Opportunity'],
                    ],
                ],
            ]);

        $account = Account::first();
        $opportunities = $account->opportunities;

        expect($opportunities)->toHaveCount(2);
        expect($opportunities[0]->Name)->toBe('Big Deal');
        expect($opportunities[1]->Name)->toBe('Bigger Deal');
    });

    it('returns empty collection when parent has no related records', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'AccountId'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Mock Account query
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '001xx000003DGb2AAG',
                        'Name'       => 'Acme Corp',
                        'attributes' => ['type' => 'Account'],
                    ],
                ],
            ]);

        // Mock empty opportunities query
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $account = Account::first();
        $opportunities = $account->opportunities;

        expect($opportunities)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        expect($opportunities)->toHaveCount(0);
    });

    it('can query relationship with additional constraints', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'AccountId'],
                ['name' => 'StageName'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Mock Account query
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '001xx000003DGb2AAG',
                        'Name'       => 'Acme Corp',
                        'attributes' => ['type' => 'Account'],
                    ],
                ],
            ]);

        // Mock relationship query with where clause
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'AccountId = \'001xx000003DGb2AAG\'') &&
                       str_contains($query, 'StageName = \'Closed Won\'');
            }))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '006xx000001abc1',
                        'Name'       => 'Won Deal',
                        'AccountId'  => '001xx000003DGb2AAG',
                        'StageName'  => 'Closed Won',
                        'attributes' => ['type' => 'Opportunity'],
                    ],
                ],
            ]);

        $account = Account::first();
        $wonOpportunities = $account->opportunities()->where('StageName', 'Closed Won')->get();

        expect($wonOpportunities)->toHaveCount(1);
        expect($wonOpportunities[0]->Name)->toBe('Won Deal');
    });

    it('can count related records', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'AccountId'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Mock Account query
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '001xx000003DGb2AAG',
                        'Name'       => 'Acme Corp',
                        'attributes' => ['type' => 'Account'],
                    ],
                ],
            ]);

        // Mock COUNT query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'COUNT()') &&
                       str_contains($query, 'AccountId = \'001xx000003DGb2AAG\'');
            }))
            ->andReturn([
                'totalSize' => 5,
                'done'      => true,
                'records'   => [],
            ]);

        $account = Account::first();
        $count = $account->opportunities()->count();

        expect($count)->toBe(5);
    });

    it('uses custom foreign key when specified', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CustomAccount__c'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Create a custom model with custom foreign key
        $model = new class extends Account
        {
            public function customOpportunities()
            {
                return $this->hasMany(Opportunity::class, 'CustomAccount__c');
            }
        };

        // Mock Account query
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '001xx000003DGb2AAG',
                        'Name'       => 'Acme Corp',
                        'attributes' => ['type' => 'Account'],
                    ],
                ],
            ]);

        // Mock relationship query with custom foreign key
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                // Should use CustomAccount__c instead of AccountId
                return str_contains($query, 'CustomAccount__c = \'001xx000003DGb2AAG\'');
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $account = $model::first();
        $opportunities = $account->customOpportunities;

        expect($opportunities)->toHaveCount(0);
    });
});

describe('belongsTo relationship', function () {
    it('lazy loads parent record', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe for Opportunity
        Forrest::shouldReceive('describe')
            ->with('Opportunity')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'Name'],
                    ['name' => 'AccountId'],
                    ['name' => 'CreatedDate'],
                    ['name' => 'LastModifiedDate'],
                    ['name' => 'IsDeleted'],
                ],
            ]);

        // Mock the initial Opportunity query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'from Opportunity');
            }))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '006xx000001abc1',
                        'Name'       => 'Big Deal',
                        'AccountId'  => '001xx000003DGb2AAG',
                        'attributes' => ['type' => 'Opportunity'],
                    ],
                ],
            ]);

        // Mock describe for Account
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'Name'],
                    ['name' => 'CreatedDate'],
                    ['name' => 'LastModifiedDate'],
                    ['name' => 'IsDeleted'],
                ],
            ]);

        // Mock the lazy load query for account
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'Id = \'001xx000003DGb2AAG\'') &&
                       str_contains($query, 'from Account');
            }))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '001xx000003DGb2AAG',
                        'Name'       => 'Acme Corp',
                        'attributes' => ['type' => 'Account'],
                    ],
                ],
            ]);

        $opportunity = Opportunity::first();
        $account = $opportunity->account;

        expect($account)->toBeInstanceOf(Account::class);
        expect($account->Name)->toBe('Acme Corp');
        expect($account->Id)->toBe('001xx000003DGb2AAG');
    });

    it('returns null when foreign key is null', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'AccountId'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Mock Opportunity query with null AccountId
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '006xx000001abc1',
                        'Name'       => 'Orphan Deal',
                        'AccountId'  => null,
                        'attributes' => ['type' => 'Opportunity'],
                    ],
                ],
            ]);

        $opportunity = Opportunity::first();
        $account = $opportunity->account;

        expect($account)->toBeNull();
    });
});

describe('hasOne relationship', function () {
    it('lazy loads single related record', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Create a model instance with hasOne for testing
        $account = new class(['Id' => '001xx000003DGb2AAG', 'Name' => 'Acme Corp']) extends Account
        {
            protected $table = 'Account';

            public function primaryContact()
            {
                return $this->hasOne(Contact::class);
            }
        };

        // Mock describe for Contact
        Forrest::shouldReceive('describe')
            ->with('Contact')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'Name'],
                    ['name' => 'AccountId'],
                    ['name' => 'CreatedDate'],
                    ['name' => 'LastModifiedDate'],
                    ['name' => 'IsDeleted'],
                ],
            ]);

        // Mock hasOne query - should LIMIT 1
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'AccountId = \'001xx000003DGb2AAG\'') &&
                       str_contains($query, 'from Contact') &&
                       str_contains($query, 'limit 1');
            }))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '003xx000001abc1',
                        'Name'       => 'John Doe',
                        'AccountId'  => '001xx000003DGb2AAG',
                        'attributes' => ['type' => 'Contact'],
                    ],
                ],
            ]);

        $contact = $account->primaryContact;

        expect($contact)->toBeInstanceOf(Contact::class);
        expect($contact->Name)->toBe('John Doe');
    });

    it('returns null when no related record exists', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Create a model instance with hasOne
        $account = new class(['Id' => '001xx000003DGb2AAG', 'Name' => 'Acme Corp']) extends Account
        {
            protected $table = 'Account';

            public function primaryContact()
            {
                return $this->hasOne(Contact::class);
            }
        };

        // Mock describe for Contact
        Forrest::shouldReceive('describe')
            ->with('Contact')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'Name'],
                    ['name' => 'AccountId'],
                    ['name' => 'CreatedDate'],
                    ['name' => 'LastModifiedDate'],
                    ['name' => 'IsDeleted'],
                ],
            ]);

        // Mock empty hasOne query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'from Contact');
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $contact = $account->primaryContact;

        expect($contact)->toBeNull();
    });
});

describe('relationship foreign key conventions', function () {
    it('uses Salesforce PascalCase naming convention', function () {
        $account = new Account(['Id' => '001xx000003DGb2AAG']);

        // Test the getSalesforceForeignKey method
        $reflection = new ReflectionClass($account);
        $method = $reflection->getMethod('getSalesforceForeignKey');
        $method->setAccessible(true);

        $foreignKey = $method->invoke($account);

        expect($foreignKey)->toBe('AccountId');
        expect($foreignKey)->not->toBe('account_id');
    });

    it('works with custom object names', function () {
        $model = new class extends \Daikazu\EloquentSalesforceObjects\Models\SalesforceModel
        {
            protected $table = 'CustomObject__c';
        };

        $reflection = new ReflectionClass($model);
        $method = $reflection->getMethod('getSalesforceForeignKey');
        $method->setAccessible(true);

        $foreignKey = $method->invoke($model);

        expect($foreignKey)->toBe('CustomObject__cId');
    });
});

describe('multiple relationships', function () {
    it('can load multiple relationships on same model', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe for all objects
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'AccountId'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Mock Account query
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '001xx000003DGb2AAG',
                        'Name'       => 'Acme Corp',
                        'attributes' => ['type' => 'Account'],
                    ],
                ],
            ]);

        // Mock opportunities query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'from Opportunity');
            }))
            ->andReturn([
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '006xx000001abc1',
                        'Name'       => 'Deal 1',
                        'AccountId'  => '001xx000003DGb2AAG',
                        'attributes' => ['type' => 'Opportunity'],
                    ],
                    [
                        'Id'         => '006xx000001abc2',
                        'Name'       => 'Deal 2',
                        'AccountId'  => '001xx000003DGb2AAG',
                        'attributes' => ['type' => 'Opportunity'],
                    ],
                ],
            ]);

        // Mock contacts query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'from Contact');
            }))
            ->andReturn([
                'totalSize' => 3,
                'done'      => true,
                'records'   => [
                    [
                        'Id'         => '003xx000001abc1',
                        'Name'       => 'Contact 1',
                        'AccountId'  => '001xx000003DGb2AAG',
                        'attributes' => ['type' => 'Contact'],
                    ],
                    [
                        'Id'         => '003xx000001abc2',
                        'Name'       => 'Contact 2',
                        'AccountId'  => '001xx000003DGb2AAG',
                        'attributes' => ['type' => 'Contact'],
                    ],
                    [
                        'Id'         => '003xx000001abc3',
                        'Name'       => 'Contact 3',
                        'AccountId'  => '001xx000003DGb2AAG',
                        'attributes' => ['type' => 'Contact'],
                    ],
                ],
            ]);

        $account = Account::first();
        $opportunities = $account->opportunities;
        $contacts = $account->contacts;

        expect($opportunities)->toHaveCount(2);
        expect($contacts)->toHaveCount(3);
    });
});
