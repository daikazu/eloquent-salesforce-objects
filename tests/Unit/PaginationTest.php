<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
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

describe('paginate method', function () {
    it('paginates results with total count', function () {
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

        // Mock the COUNT query first
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') !== false;
            }))
            ->andReturn([
                'totalSize' => 150, // Salesforce returns count in totalSize for COUNT queries
                'done'      => true,
                'records'   => [],
            ])
            ->ordered();

        // Mock the actual data query second
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') === false &&
                       str_contains($query, 'from Account') &&
                       str_contains($query, 'limit 15');
            }))
            ->andReturn([
                'totalSize' => 15,
                'done'      => true,
                'records'   => array_map(fn ($i) => [
                    'Id'         => "001xx00000{$i}",
                    'Name'       => "Account {$i}",
                    'attributes' => ['type' => 'Account'],
                ], range(1, 15)),
            ])
            ->ordered();

        $paginator = Account::paginate(15);

        expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class);
        expect($paginator->total())->toBe(150);
        expect($paginator->perPage())->toBe(15);
        expect($paginator->currentPage())->toBe(1);
        expect($paginator->lastPage())->toBe(10);
        expect($paginator->count())->toBe(15);
    });

    it('paginates specific page', function () {
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

        // Mock the COUNT query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') !== false;
            }))
            ->andReturn([
                'totalSize' => 100,
                'done'      => true,
                'records'   => [],
            ])
            ->ordered();

        // Mock the data query with offset for page 3
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') === false &&
                       str_contains($query, 'limit 20') &&
                       str_contains($query, 'offset 40'); // Page 3 = (3-1) * 20
            }))
            ->andReturn([
                'totalSize' => 20,
                'done'      => true,
                'records'   => array_map(fn ($i) => [
                    'Id'         => "001xx00000{$i}",
                    'Name'       => "Account {$i}",
                    'attributes' => ['type' => 'Account'],
                ], range(1, 20)),
            ])
            ->ordered();

        $paginator = Account::paginate(20, ['*'], 'page', 3);

        expect($paginator->currentPage())->toBe(3);
        expect($paginator->count())->toBe(20);
    });

    it('skips COUNT query when total is provided', function () {
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

        // Should NOT receive a COUNT query
        Forrest::shouldNotReceive('query')->with(Mockery::on(function ($query) {
            return stripos($query, 'count(') !== false;
        }));

        // Mock the data query only
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') === false;
            }))
            ->andReturn([
                'totalSize' => 10,
                'done'      => true,
                'records'   => array_map(fn ($i) => [
                    'Id'         => "001xx00000{$i}",
                    'Name'       => "Account {$i}",
                    'attributes' => ['type' => 'Account'],
                ], range(1, 10)),
            ]);

        $paginator = Account::paginate(10, ['*'], 'page', 1, 100); // Pass total=100

        expect($paginator->total())->toBe(100);
        expect($paginator->count())->toBe(10);
    });

    it('limits total to 2000 due to SOQL OFFSET limit', function () {
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

        // Mock COUNT query returning more than 2000
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') !== false;
            }))
            ->andReturn([
                'totalSize' => 5000, // More than SOQL OFFSET limit
                'done'      => true,
                'records'   => [],
            ])
            ->ordered();

        // Mock the data query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') === false;
            }))
            ->andReturn([
                'totalSize' => 15,
                'done'      => true,
                'records'   => array_map(fn ($i) => [
                    'Id'         => "001xx00000{$i}",
                    'Name'       => "Account {$i}",
                    'attributes' => ['type' => 'Account'],
                ], range(1, 15)),
            ])
            ->ordered();

        $paginator = Account::paginate(15);

        // Should be capped at 2000
        expect($paginator->total())->toBe(2000);
        expect($paginator->lastPage())->toBe(134); // ceil(2000 / 15)
    });

    it('returns empty results when no records exist', function () {
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

        // Mock COUNT query returning 0
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') !== false;
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        // Should not make a data query since total is 0
        Forrest::shouldNotReceive('query')->with(Mockery::on(function ($query) {
            return stripos($query, 'count(') === false;
        }));

        $paginator = Account::paginate(15);

        expect($paginator->total())->toBe(0);
        expect($paginator->count())->toBe(0);
        expect($paginator->isEmpty())->toBeTrue();
    });

    it('paginates with where clauses', function () {
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

        // Mock COUNT query with where clause
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') !== false &&
                       str_contains($query, "Industry = 'Technology'");
            }))
            ->andReturn([
                'totalSize' => 50,
                'done'      => true,
                'records'   => [],
            ])
            ->ordered();

        // Mock data query with where clause
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') === false &&
                       str_contains($query, "Industry = 'Technology'") &&
                       str_contains($query, 'limit 10');
            }))
            ->andReturn([
                'totalSize' => 10,
                'done'      => true,
                'records'   => array_map(fn ($i) => [
                    'Id'         => "001xx00000{$i}",
                    'Name'       => "Tech Company {$i}",
                    'Industry'   => 'Technology',
                    'attributes' => ['type' => 'Account'],
                ], range(1, 10)),
            ])
            ->ordered();

        $paginator = Account::where('Industry', 'Technology')->paginate(10);

        expect($paginator->total())->toBe(50);
        expect($paginator->count())->toBe(10);
    });
});

describe('simplePaginate method', function () {
    it('paginates without counting total', function () {
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

        // Should NOT make a COUNT query
        Forrest::shouldNotReceive('query')->with(Mockery::on(function ($query) {
            return stripos($query, 'count(') !== false;
        }));

        // Mock data query - fetches perPage + 1 (16 instead of 15)
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'limit 16'); // perPage + 1
            }))
            ->andReturn([
                'totalSize' => 16,
                'done'      => true,
                'records'   => array_map(fn ($i) => [
                    'Id'         => "001xx00000{$i}",
                    'Name'       => "Account {$i}",
                    'attributes' => ['type' => 'Account'],
                ], range(1, 16)),
            ]);

        $paginator = Account::simplePaginate(15);

        expect($paginator)->toBeInstanceOf(Paginator::class);
        expect($paginator->perPage())->toBe(15);
        expect($paginator->currentPage())->toBe(1);
        expect($paginator->hasMorePages())->toBeTrue(); // Because we got 16 results
        expect($paginator->count())->toBe(15); // Returns only perPage items
    });

    it('detects last page when fewer results returned', function () {
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

        // Mock data query - returns less than perPage + 1 (only 10 results)
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, 'limit 16'); // perPage + 1
            }))
            ->andReturn([
                'totalSize' => 10,
                'done'      => true,
                'records'   => array_map(fn ($i) => [
                    'Id'         => "001xx00000{$i}",
                    'Name'       => "Account {$i}",
                    'attributes' => ['type' => 'Account'],
                ], range(1, 10)),
            ]);

        $paginator = Account::simplePaginate(15);

        expect($paginator->hasMorePages())->toBeFalse(); // No more pages
        expect($paginator->count())->toBe(10);
    });

    it('paginates specific page with offset', function () {
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

        // Page 2 at 20 per page starts at offset 20 and fetches one extra row
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(fn ($query) => str_contains($query, 'limit 21')
                && str_contains($query, 'offset 20')))
            ->andReturn([
                'totalSize' => 21,
                'done'      => true,
                'records'   => array_map(fn ($i) => [
                    'Id'               => "001xx00000{$i}",
                    'Name'             => "Account {$i}",
                    'CreatedDate'      => '2024-01-01T00:00:00.000+0000',
                    'LastModifiedDate' => '2024-01-01T00:00:00.000+0000',
                    'IsDeleted'        => false,
                    'attributes'       => ['type' => 'Account'],
                ], range(1, 21)),
            ]);

        $paginator = Account::simplePaginate(20, ['*'], 'page', 2);

        expect($paginator->currentPage())->toBe(2);
        expect($paginator->count())->toBe(20); // Should show 20 items (sliced from 21)
        expect($paginator->hasMorePages())->toBeTrue();
    });

    it('works with where clauses', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Type'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Mock data query with where clause
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return str_contains($query, "Type = 'Customer'") &&
                       str_contains($query, 'limit 11'); // perPage + 1
            }))
            ->andReturn([
                'totalSize' => 8,
                'done'      => true,
                'records'   => array_map(fn ($i) => [
                    'Id'         => "001xx00000{$i}",
                    'Name'       => "Customer {$i}",
                    'Type'       => 'Customer',
                    'attributes' => ['type' => 'Account'],
                ], range(1, 8)),
            ]);

        $paginator = Account::where('Type', 'Customer')->simplePaginate(10);

        expect($paginator->count())->toBe(8);
        expect($paginator->hasMorePages())->toBeFalse();
    });
});

describe('pagination edge cases', function () {
    it('handles empty results for pagination', function () {
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

        // Mock COUNT query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') !== false;
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $paginator = Account::where('Name', 'NonExistent')->paginate(15);

        expect($paginator->total())->toBe(0);
        expect($paginator->isEmpty())->toBeTrue();
    });

    it('handles empty results for simple pagination', function () {
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

        // Mock data query returning no results
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $paginator = Account::where('Name', 'NonExistent')->simplePaginate(15);

        expect($paginator->isEmpty())->toBeTrue();
        expect($paginator->hasMorePages())->toBeFalse();
    });

    it('paginates with custom columns', function () {
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

        // Mock COUNT query
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') !== false;
            }))
            ->andReturn([
                'totalSize' => 30,
                'done'      => true,
                'records'   => [],
            ])
            ->ordered();

        // Mock data query with specific columns
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return stripos($query, 'count(') === false &&
                       str_contains($query, 'select Id, Name');
            }))
            ->andReturn([
                'totalSize' => 10,
                'done'      => true,
                'records'   => array_map(fn ($i) => [
                    'Id'         => "001xx00000{$i}",
                    'Name'       => "Account {$i}",
                    'attributes' => ['type' => 'Account'],
                ], range(1, 10)),
            ]);

        $paginator = Account::paginate(10, ['Id', 'Name']);

        expect($paginator->total())->toBe(30);
        expect($paginator->count())->toBe(10);
    });
});

describe('default page size', function () {
    it('uses the default_page_size config when no per-page value is given', function () {
        config(['eloquent-salesforce-objects.default_page_size' => 50]);

        $paginator = Account::query()->paginate(null, ['Id'], 'page', 1, 0);

        expect($paginator->perPage())->toBe(50);
    });

    it('defaults to 200 per page out of the box', function () {
        expect((new Account)->getPerPage())->toBe(200);
    });

    it('lets a model override the page size with $perPage', function () {
        config(['eloquent-salesforce-objects.default_page_size' => 50]);

        $model = new class extends Account
        {
            protected $perPage = 10;
        };

        expect($model->getPerPage())->toBe(10);
    });

    it('respects setPerPage() at runtime', function () {
        config(['eloquent-salesforce-objects.default_page_size' => 50]);

        expect((new Account)->setPerPage(30)->getPerPage())->toBe(30);
    });

    it('prefers an explicit per-page argument', function () {
        config(['eloquent-salesforce-objects.default_page_size' => 50]);

        $paginator = Account::query()->paginate(25, ['Id'], 'page', 1, 0);

        expect($paginator->perPage())->toBe(25);
    });

    it('applies the config to simplePaginate', function () {
        config(['eloquent-salesforce-objects.default_page_size' => 40]);

        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(fn ($soql) => str_contains($soql, 'limit 41')))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        expect(Account::query()->simplePaginate(null, ['Id'])->perPage())->toBe(40);
    });
});

describe('pagination with $defaultColumns', function () {
    beforeEach(function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn(['fields' => array_map(
            fn ($name) => ['name' => $name],
            ['Id', 'Name', 'Type', 'Description', 'CreatedDate', 'LastModifiedDate', 'IsDeleted'],
        )]);
    });

    it('paginate() selects the model\'s default columns, like get()', function () {
        Forrest::shouldReceive('query')->once()
            ->with(Mockery::on(fn ($q) => str_starts_with($q, 'select Id, Name, Type, Industry, ')
                && str_contains($q, ', OwnerId, CreatedDate, LastModifiedDate, IsDeleted from Account')
                && ! str_contains($q, 'Description')))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Account::paginate(10, ['*'], 'page', 1, 5);
    });

    it('simplePaginate() selects the model\'s default columns, like get()', function () {
        Forrest::shouldReceive('query')->once()
            ->with(Mockery::on(fn ($q) => str_starts_with($q, 'select Id, Name, Type, Industry, ')
                && ! str_contains($q, 'Description')))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Account::simplePaginate(10);
    });

    it('allColumns()->paginate() selects every field', function () {
        Forrest::shouldReceive('query')->once()
            ->with(Mockery::on(fn ($q) => str_contains($q, 'Description')))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Account::allColumns()->paginate(10, ['*'], 'page', 1, 5);
    });
});
