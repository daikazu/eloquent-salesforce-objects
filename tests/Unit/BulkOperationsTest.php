<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
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

describe('bulk insert', function () {
    it('inserts multiple records with array input', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $records = [
            ['Name' => 'Company A', 'Industry' => 'Technology'],
            ['Name' => 'Company B', 'Industry' => 'Finance'],
            ['Name' => 'Company C', 'Industry' => 'Healthcare'],
        ];

        Forrest::shouldReceive('post')
            ->once()
            ->with('v64.0/composite/sobjects', Mockery::on(function ($args) {
                return isset($args['body']['records']) &&
                       count($args['body']['records']) === 3 &&
                       $args['body']['allOrNone'] === false;
            }))
            ->andReturn([
                'results' => [
                    ['id' => '001xx000001', 'success' => true],
                    ['id' => '001xx000002', 'success' => true],
                    ['id' => '001xx000003', 'success' => true],
                ],
            ]);

        $results = Account::query()->insert($records);

        expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
        expect($results)->toHaveCount(3);
        expect($results[0]['success'])->toBeTrue();
    });

    it('inserts multiple records with collection input', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $records = collect([
            ['Name' => 'Company A', 'Industry' => 'Technology'],
            ['Name' => 'Company B', 'Industry' => 'Finance'],
        ]);

        Forrest::shouldReceive('post')
            ->once()
            ->andReturn([
                'results' => [
                    ['id' => '001xx000001', 'success' => true],
                    ['id' => '001xx000002', 'success' => true],
                ],
            ]);

        $results = Account::query()->insert($records);

        expect($results)->toHaveCount(2);
    });

    it('returns empty collection for empty input', function () {
        $results = Account::query()->insert([]);

        expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
        expect($results)->toHaveCount(0);
    });

    it('automatically chunks records into batches of 200', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Create 250 records (should be split into 2 chunks: 200 + 50)
        $records = [];
        for ($i = 1; $i <= 250; $i++) {
            $records[] = ['Name' => "Company {$i}", 'Industry' => 'Technology'];
        }

        // Expect first chunk of 200
        Forrest::shouldReceive('post')
            ->once()
            ->with('v64.0/composite/sobjects', Mockery::on(function ($args) {
                return count($args['body']['records']) === 200;
            }))
            ->andReturn([
                'results' => array_fill(0, 200, ['id' => '001xx000001', 'success' => true]),
            ]);

        // Expect second chunk of 50
        Forrest::shouldReceive('post')
            ->once()
            ->with('v64.0/composite/sobjects', Mockery::on(function ($args) {
                return count($args['body']['records']) === 50;
            }))
            ->andReturn([
                'results' => array_fill(0, 50, ['id' => '001xx000002', 'success' => true]),
            ]);

        $results = Account::query()->insert($records);

        expect($results)->toHaveCount(250);
    });

    it('uses allOrNone parameter when specified', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $records = [
            ['Name' => 'Company A'],
            ['Name' => 'Company B'],
        ];

        Forrest::shouldReceive('post')
            ->once()
            ->with('v64.0/composite/sobjects', Mockery::on(function ($args) {
                return $args['body']['allOrNone'] === true;
            }))
            ->andReturn([
                'results' => [
                    ['id' => '001xx000001', 'success' => true],
                    ['id' => '001xx000002', 'success' => true],
                ],
            ]);

        Account::query()->insert($records, allOrNone: true);

        expect(true)->toBeTrue(); // Assertion is in the mock verification
    });

    it('handles response without results key', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $records = [['Name' => 'Company A']];

        // Some API responses may not have a 'results' key
        Forrest::shouldReceive('post')
            ->once()
            ->andReturn(['id' => '001xx000001', 'success' => true]);

        $results = Account::query()->insert($records);

        expect($results)->toHaveCount(1);
        expect($results[0]['success'])->toBeTrue();
    });

    it('throws exception on failure when throw_exceptions is true', function () {
        config(['eloquent-salesforce-objects.throw_exceptions' => true]);

        Forrest::shouldReceive('hasToken')->andReturn(true);

        $records = [['Name' => 'Company A']];

        Forrest::shouldReceive('post')
            ->once()
            ->andThrow(new Exception('Salesforce API Error'));

        expect(fn () => Account::query()->insert($records))
            ->toThrow(Exception::class);
    });

    it('continues processing chunks on failure when throw_exceptions is false', function () {
        config(['eloquent-salesforce-objects.throw_exceptions' => false]);

        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Create 250 records
        $records = array_fill(0, 250, ['Name' => 'Company']);

        // First chunk fails
        Forrest::shouldReceive('post')
            ->once()
            ->with('v64.0/composite/sobjects', Mockery::on(function ($args) {
                return count($args['body']['records']) === 200;
            }))
            ->andThrow(new Exception('First chunk failed'));

        // Second chunk succeeds
        Forrest::shouldReceive('post')
            ->once()
            ->with('v64.0/composite/sobjects', Mockery::on(function ($args) {
                return count($args['body']['records']) === 50;
            }))
            ->andReturn([
                'results' => array_fill(0, 50, ['id' => '001xx000001', 'success' => true]),
            ]);

        $results = Account::query()->insert($records);

        // Should only have results from second chunk
        expect($results)->toHaveCount(50);
    });
});

describe('bulk delete', function () {
    it('deletes multiple records', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
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

        // Mock query to get records
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 3,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '001xx000002', 'Name' => 'Company B', 'attributes' => ['type' => 'Account']],
                    ['Id' => '001xx000003', 'Name' => 'Company C', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        // Mock bulk delete
        Forrest::shouldReceive('delete')
            ->once()
            ->with(Mockery::on(function ($url) {
                return str_contains($url, 'composite/sobjects') &&
                       str_contains($url, 'ids=001xx000001,001xx000002,001xx000003') &&
                       str_contains($url, 'allOrNone=false');
            }))
            ->andReturn([
                'results' => [
                    ['id' => '001xx000001', 'success' => true],
                    ['id' => '001xx000002', 'success' => true],
                    ['id' => '001xx000003', 'success' => true],
                ],
            ]);

        $deleted = Account::where('Industry', 'Technology')->delete();

        expect($deleted)->toBe(3);
    });

    it('returns 0 when no records match', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Mock query returning no records
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $deleted = Account::where('Name', 'NonExistent')->delete();

        expect($deleted)->toBe(0);
    });

    it('automatically chunks deletes into batches of 200', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Create 250 records
        $records = [];
        for ($i = 1; $i <= 250; $i++) {
            $records[] = [
                'Id'         => sprintf('001xx%06d', $i),
                'Name'       => "Company {$i}",
                'attributes' => ['type' => 'Account'],
            ];
        }

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 250,
                'done'      => true,
                'records'   => $records,
            ]);

        // Expect first chunk of 200
        Forrest::shouldReceive('delete')
            ->once()
            ->with(Mockery::on(function ($url) {
                $parts = parse_url($url);
                parse_str($parts['query'] ?? '', $query);
                $ids = explode(',', $query['ids'] ?? '');
                return count($ids) === 200;
            }))
            ->andReturn([
                'results' => array_fill(0, 200, ['id' => '001xx000001', 'success' => true]),
            ]);

        // Expect second chunk of 50
        Forrest::shouldReceive('delete')
            ->once()
            ->with(Mockery::on(function ($url) {
                $parts = parse_url($url);
                parse_str($parts['query'] ?? '', $query);
                $ids = explode(',', $query['ids'] ?? '');
                return count($ids) === 50;
            }))
            ->andReturn([
                'results' => array_fill(0, 50, ['id' => '001xx000002', 'success' => true]),
            ]);

        $deleted = Account::query()->delete();

        expect($deleted)->toBe(250);
    });

    it('uses allOrNone parameter when specified', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
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
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '001xx000002', 'Name' => 'Company B', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        Forrest::shouldReceive('delete')
            ->once()
            ->with(Mockery::on(function ($url) {
                return str_contains($url, 'allOrNone=true');
            }))
            ->andReturn([
                'results' => [
                    ['id' => '001xx000001', 'success' => true],
                    ['id' => '001xx000002', 'success' => true],
                ],
            ]);

        Account::query()->delete(allOrNone: true);

        expect(true)->toBeTrue(); // Assertion is in the mock verification
    });

    it('counts only successful deletes', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
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
                'totalSize' => 3,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '001xx000002', 'Name' => 'Company B', 'attributes' => ['type' => 'Account']],
                    ['Id' => '001xx000003', 'Name' => 'Company C', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        // One success, one failure, one success
        Forrest::shouldReceive('delete')
            ->once()
            ->andReturn([
                'results' => [
                    ['id' => '001xx000001', 'success' => true],
                    ['id' => '001xx000002', 'success' => false, 'errors' => [['message' => 'Record locked']]],
                    ['id' => '001xx000003', 'success' => true],
                ],
            ]);

        $deleted = Account::query()->delete();

        expect($deleted)->toBe(2); // Only 2 successful deletes
    });

    it('handles response without detailed results', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
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
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '001xx000002', 'Name' => 'Company B', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        // Response without 'results' key - assume all succeeded
        Forrest::shouldReceive('delete')
            ->once()
            ->andReturn(['success' => true]);

        $deleted = Account::query()->delete();

        expect($deleted)->toBe(2); // Assumes all succeeded
    });

    it('throws exception on failure when throw_exceptions is true', function () {
        config(['eloquent-salesforce-objects.throw_exceptions' => true]);

        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
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
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        Forrest::shouldReceive('delete')
            ->once()
            ->andThrow(new Exception('Salesforce API Error'));

        expect(fn () => Account::query()->delete())
            ->toThrow(Exception::class);
    });

    it('throws exception on failure when allOrNone is true', function () {
        config(['eloquent-salesforce-objects.throw_exceptions' => false]);

        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
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
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        Forrest::shouldReceive('delete')
            ->once()
            ->andThrow(new Exception('Salesforce API Error'));

        // Should throw even with throw_exceptions=false because allOrNone=true
        expect(fn () => Account::query()->delete(allOrNone: true))
            ->toThrow(Exception::class);
    });

    it('continues processing chunks on failure when throw_exceptions is false and allOrNone is false', function () {
        config(['eloquent-salesforce-objects.throw_exceptions' => false]);

        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
        Forrest::shouldReceive('describe')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        // Create 250 records
        $records = [];
        for ($i = 1; $i <= 250; $i++) {
            $records[] = [
                'Id'         => sprintf('001xx%06d', $i),
                'Name'       => "Company {$i}",
                'attributes' => ['type' => 'Account'],
            ];
        }

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 250,
                'done'      => true,
                'records'   => $records,
            ]);

        // First chunk fails
        Forrest::shouldReceive('delete')
            ->once()
            ->andThrow(new Exception('First chunk failed'));

        // Second chunk succeeds
        Forrest::shouldReceive('delete')
            ->once()
            ->andReturn([
                'results' => array_fill(0, 50, ['id' => '001xx000001', 'success' => true]),
            ]);

        $deleted = Account::query()->delete(allOrNone: false);

        // Should only count second chunk
        expect($deleted)->toBe(50);
    });
});

describe('truncate', function () {
    it('delegates to delete method', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock describe
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
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Company A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '001xx000002', 'Name' => 'Company B', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        Forrest::shouldReceive('delete')
            ->once()
            ->andReturn([
                'results' => [
                    ['id' => '001xx000001', 'success' => true],
                    ['id' => '001xx000002', 'success' => true],
                ],
            ]);

        $deleted = Account::query()->truncate();

        expect($deleted)->toBe(2);
    });
});

describe('bulk operation size limits', function () {
    it('enforces adapter limit on bulkCreate', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Try to create 201 records in a single call to the adapter (not through SOQLBuilder)
        // This should fail because the adapter has a hard limit
        $adapter = app(\Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter::class);

        $records = array_fill(0, 201, ['Name' => 'Company']);

        expect(fn () => $adapter->bulkCreate('Account', $records))
            ->toThrow(SalesforceException::class, 'limited to 200 records');
    });

    it('enforces adapter limit on bulkDelete', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        $adapter = app(\Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter::class);

        $ids = array_fill(0, 201, '001xx000001');

        expect(fn () => $adapter->bulkDelete('Account', $ids))
            ->toThrow(SalesforceException::class, 'limited to 200 records');
    });

    it('builder automatically chunks but adapter validates', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // SOQLBuilder should chunk automatically, so 250 records should work
        $records = array_fill(0, 250, ['Name' => 'Company']);

        Forrest::shouldReceive('post')
            ->twice() // Should be called twice (200 + 50)
            ->andReturn([
                'results' => [],
            ]);

        // Should not throw because builder chunks it
        $results = Account::query()->insert($records);

        expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    });
});
