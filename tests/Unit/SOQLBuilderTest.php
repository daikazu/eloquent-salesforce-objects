<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

describe('basic queries', function () {
    it('gets all records', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

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

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'select') &&
                       str_contains($query, 'from Account');
            }))
            ->andReturn([
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '001xx000002', 'Name' => 'Company B', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $accounts = Account::get();

        expect($accounts)->toHaveCount(2);
        expect($accounts[0]->Name)->toBe('Company A');
        expect($accounts[1]->Name)->toBe('Company B');
    });

    it('selects specific columns', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
            ],
        ]);

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $accounts = Account::select(['Id', 'Name'])->get();
        $sql = Account::select(['Id', 'Name'])->toSql();

        expect($accounts)->toHaveCount(1);
        expect($sql)->toContain('select Id, Name from Account');
    });

    it('gets first record', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
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
                return str_contains($query, 'limit 1');
            }))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $account = Account::first();

        expect($account)->toBeInstanceOf(Account::class);
        expect($account->Name)->toBe('Company A');
    });

    it('returns null when first() finds no records', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
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
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $account = Account::first();

        expect($account)->toBeNull();
    });

    it('finds record by ID', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
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
                return str_contains($query, "Id = '001xx000001'") &&
                       str_contains($query, 'limit 1');
            }))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $account = Account::find('001xx000001');

        expect($account)->toBeInstanceOf(Account::class);
        expect($account->Id)->toBe('001xx000001');
    });

    it('returns null when find() finds no record', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
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
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $account = Account::find('nonexistent');

        expect($account)->toBeNull();
    });

    it('findOrFail throws exception when not found', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
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
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        expect(fn () => Account::findOrFail('nonexistent'))
            ->toThrow(ModelNotFoundException::class);
    });

    it('finds multiple records by IDs', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
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
                return str_contains($query, 'Id in (');
            }))
            ->andReturn([
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '001xx000002', 'Name' => 'Company B', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $accounts = Account::find(['001xx000001', '001xx000002']);

        expect($accounts)->toHaveCount(2);
    });
});

describe('where clauses', function () {
    it('adds simple where clause', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Industry'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, "Industry = 'Technology'");
            }))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Tech Co', 'Industry' => 'Technology', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $accounts = Account::where('Industry', 'Technology')->get();

        expect($accounts)->toHaveCount(1);
        expect($accounts[0]->Industry)->toBe('Technology');
    });

    it('adds multiple where clauses', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Industry'],
                ['name' => 'Type'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::where('Industry', 'Technology')
            ->where('Type', 'Customer')
            ->toSql();

        expect($sql)->toContain("Industry = 'Technology'");
        expect($sql)->toContain("Type = 'Customer'");
    });

    it('supports whereIn clause', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Industry'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::whereIn('Industry', ['Technology', 'Finance', 'Healthcare'])->toSql();

        expect($sql)->toContain('Industry in (');
        expect($sql)->toContain('Technology');
        expect($sql)->toContain('Finance');
        expect($sql)->toContain('Healthcare');
    });

    it('supports whereNotIn clause', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Industry'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::whereNotIn('Industry', ['Retail'])->toSql();

        expect($sql)->toContain('Industry not in (');
        expect($sql)->toContain('Retail');
    });

    it('supports whereNull clause', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Industry'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $query = Account::whereNull('Industry')->toSql();

        // Laravel generates "= NULL", not "is null"
        expect($query)->toContain('NULL');
    });

    it('supports whereNotNull clause', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Industry'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $query = Account::whereNotNull('Industry')->toSql();

        // Laravel generates "<> NULL", not "is not null"
        expect($query)->toContain('NULL');
    });

    it('supports whereBetween clause', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'AnnualRevenue'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $query = Account::whereBetween('AnnualRevenue', [1000000, 5000000])->toSql();

        // Laravel generates "between X and Y" syntax
        expect($query)->toContain('between');
        expect($query)->toContain('1000000');
        expect($query)->toContain('5000000');
    });

    it('supports where with operators', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'AnnualRevenue'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::where('AnnualRevenue', '>', 1000000)->toSql();

        expect($sql)->toContain('AnnualRevenue >');
        expect($sql)->toContain('1000000');
    });

    it('supports LIKE operator', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::where('Name', 'like', 'Acme%')->toSql();

        expect($sql)->toContain('Name like');
        expect($sql)->toContain('Acme%');
    });
});

describe('ordering and limiting', function () {
    it('orders by column ascending', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::orderBy('Name', 'asc')->toSql();

        expect($sql)->toContain('order by Name asc');
    });

    it('orders by column descending', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::orderBy('CreatedDate', 'desc')->toSql();

        expect($sql)->toContain('order by CreatedDate desc');
    });

    it('orders by multiple columns', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Industry'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::orderBy('Industry', 'asc')->orderBy('Name', 'desc')->toSql();

        expect($sql)->toContain('order by Industry asc');
        expect($sql)->toContain('Name desc');
    });

    it('limits results', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::limit(10)->toSql();

        expect($sql)->toContain('limit 10');
    });

    it('uses offset', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::limit(10)->offset(20)->toSql();

        expect($sql)->toContain('limit 10');
        expect($sql)->toContain('offset 20');
    });

    it('uses take as alias for limit', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::take(5)->toSql();

        expect($sql)->toContain('limit 5');
    });

    it('uses skip as alias for offset', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::skip(10)->toSql();

        expect($sql)->toContain('offset 10');
    });
});

describe('query chaining', function () {
    it('chains multiple query methods', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Industry'],
                ['name' => 'AnnualRevenue'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::where('Industry', 'Technology')
            ->where('AnnualRevenue', '>', 1000000)
            ->orderBy('Name', 'asc')
            ->limit(5)
            ->toSql();

        expect($sql)->toContain("Industry = 'Technology'");
        expect($sql)->toContain('AnnualRevenue >');
        expect($sql)->toContain('1000000');
        expect($sql)->toContain('order by Name asc');
        expect($sql)->toContain('limit 5');
    });

    it('can reuse query builder', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Industry'],
                ['name' => 'Type'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // First query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, "Type = 'Customer'");
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        // Second query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, "Type = 'Partner'");
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $query = Account::where('Industry', 'Technology');

        $customers = $query->where('Type', 'Customer')->get();
        $partners = $query->where('Type', 'Partner')->get();

        expect($customers)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        expect($partners)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
    });
});

describe('toSql method', function () {
    it('generates SOQL query string', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'Name'],
                    ['name' => 'Industry'],
                ],
            ]);

        $sql = Account::where('Industry', 'Technology')->toSql();

        expect($sql)->toBeString();
        expect($sql)->toContain('select');
        expect($sql)->toContain('from Account');
        expect($sql)->toContain('Technology');
    });

    it('removes backticks from query', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
            ],
        ]);

        $sql = Account::select(['Id', 'Name'])->toSql();

        expect($sql)->not->toContain('`');
    });
});

describe('from method', function () {
    it('switches table for query', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')
            ->with('Contact')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'FirstName'],
                    ['name' => 'LastName'],
                    ['name' => 'CreatedDate'],
                    ['name' => 'LastModifiedDate'],
                    ['name' => 'IsDeleted'],
                ],
            ]);

        $sql = Account::from('Contact')->toSql();

        expect($sql)->toContain('from Contact');
    });
});

describe('whereTime method', function () {
    it('delegates to where method', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::whereTime('CreatedDate', '>', '2024-01-01')->toSql();

        expect($sql)->toContain('CreatedDate >');
        expect($sql)->toContain('2024-01-01');
    });
});

describe('describe method', function () {
    it('returns columns from adapter', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock the describe method
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'Name'],
                    ['name' => 'Industry'],
                ],
            ]);

        // Mock the post method that resolveFields uses
        Forrest::shouldReceive('post')
            ->andReturn([
                'fields' => [
                    ['name' => 'Id'],
                    ['name' => 'Name'],
                    ['name' => 'Industry'],
                ],
            ]);

        $columns = Account::query()->describe();

        expect($columns)->toBeArray();
        expect($columns)->not->toBeEmpty();
    });

    it('returns model columns if set', function () {
        $model = new class extends Account
        {
            public $columns = ['Id', 'Name', 'CustomField__c'];
        };

        $columns = $model->newQuery()->describe();

        expect($columns)->toBe(['Id', 'Name', 'CustomField__c']);
    });
});

describe('getPicklistValues method', function () {
    it('retrieves picklist values from adapter', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock the describe method
        Forrest::shouldReceive('describe')
            ->with('Account')
            ->andReturn([
                'fields' => [
                    [
                        'name'           => 'Industry',
                        'type'           => 'picklist',
                        'picklistValues' => [
                            ['value' => 'Technology', 'label' => 'Technology'],
                            ['value' => 'Finance', 'label' => 'Finance'],
                            ['value' => 'Healthcare', 'label' => 'Healthcare'],
                        ],
                    ],
                ],
            ]);

        // Mock the post method that picklistValues uses
        Forrest::shouldReceive('post')
            ->andReturn([
                'fields' => [
                    [
                        'name'           => 'Industry',
                        'type'           => 'picklist',
                        'picklistValues' => [
                            ['value' => 'Technology', 'label' => 'Technology'],
                            ['value' => 'Finance', 'label' => 'Finance'],
                            ['value' => 'Healthcare', 'label' => 'Healthcare'],
                        ],
                    ],
                ],
            ]);

        $values = Account::query()->getPicklistValues('Industry');

        expect($values)->toBeArray();
    });
});

describe('batch method', function () {
    it('throws exception for not implemented', function () {
        expect(fn () => Account::query()->batch())
            ->toThrow(\BadMethodCallException::class, 'not yet implemented');
    });
});
