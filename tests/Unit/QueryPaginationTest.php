<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);
});

afterEach(function () {
    Mockery::close();
});

/**
 * Shared describe mock — returns the minimal field set needed for Account queries.
 */
function mockAccountDescribe(): void
{
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
}

// ---------------------------------------------------------------------------
// executeQuery() pagination — tested via Account::get()
// ---------------------------------------------------------------------------

describe('executeQuery() pagination', function () {

    it('merges records across two pages when nextRecordsUrl is present', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        mockAccountDescribe();

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize'      => 4,
                'done'           => false,
                'nextRecordsUrl' => '/services/data/v64.0/query/01gxx-2000',
                'records'        => [
                    ['Id' => '001', 'Name' => 'A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '002', 'Name' => 'B', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        Forrest::shouldReceive('next')
            ->once()
            ->with('/services/data/v64.0/query/01gxx-2000')
            ->andReturn([
                'totalSize' => 4,
                'done'      => true,
                'records'   => [
                    ['Id' => '003', 'Name' => 'C', 'attributes' => ['type' => 'Account']],
                    ['Id' => '004', 'Name' => 'D', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $results = Account::get();

        expect($results)->toHaveCount(4);
        expect($results->pluck('Id')->all())->toBe(['001', '002', '003', '004']);
    });

    it('follows three pages of results until done is true', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        mockAccountDescribe();

        // Page 1 — points to page 2
        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize'      => 6,
                'done'           => false,
                'nextRecordsUrl' => '/services/data/v64.0/query/page2',
                'records'        => [
                    ['Id' => '001', 'Name' => 'A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '002', 'Name' => 'B', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        // Page 2 — points to page 3
        Forrest::shouldReceive('next')
            ->once()
            ->with('/services/data/v64.0/query/page2')
            ->andReturn([
                'totalSize'      => 6,
                'done'           => false,
                'nextRecordsUrl' => '/services/data/v64.0/query/page3',
                'records'        => [
                    ['Id' => '003', 'Name' => 'C', 'attributes' => ['type' => 'Account']],
                    ['Id' => '004', 'Name' => 'D', 'attributes' => ['type' => 'Account']],
                ],
            ])
            ->ordered();

        // Page 3 — no nextRecordsUrl, loop terminates
        Forrest::shouldReceive('next')
            ->once()
            ->with('/services/data/v64.0/query/page3')
            ->andReturn([
                'totalSize' => 6,
                'done'      => true,
                'records'   => [
                    ['Id' => '005', 'Name' => 'E', 'attributes' => ['type' => 'Account']],
                    ['Id' => '006', 'Name' => 'F', 'attributes' => ['type' => 'Account']],
                ],
            ])
            ->ordered();

        $results = Account::get();

        expect($results)->toHaveCount(6);
        expect($results->pluck('Id')->all())->toBe(['001', '002', '003', '004', '005', '006']);
    });

    it('does not call next() when the initial response has no nextRecordsUrl', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        mockAccountDescribe();

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    ['Id' => '001', 'Name' => 'A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '002', 'Name' => 'B', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        // next() must never be called on a single-page result set
        Forrest::shouldNotReceive('next');

        $results = Account::get();

        expect($results)->toHaveCount(2);
    });

});

// ---------------------------------------------------------------------------
// cursor() pagination — tested via Account::cursor()
// ---------------------------------------------------------------------------

describe('cursor() pagination', function () {

    it('yields all records across two pages', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        mockAccountDescribe();

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize'      => 4,
                'done'           => false,
                'nextRecordsUrl' => '/services/data/v64.0/query/01gxx-2000',
                'records'        => [
                    ['Id' => '001', 'Name' => 'A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '002', 'Name' => 'B', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        Forrest::shouldReceive('next')
            ->once()
            ->with('/services/data/v64.0/query/01gxx-2000')
            ->andReturn([
                'totalSize' => 4,
                'done'      => true,
                'records'   => [
                    ['Id' => '003', 'Name' => 'C', 'attributes' => ['type' => 'Account']],
                    ['Id' => '004', 'Name' => 'D', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $yielded = [];
        foreach (Account::cursor() as $record) {
            $yielded[] = $record;
        }

        expect($yielded)->toHaveCount(4);
        expect(array_column($yielded, 'Id'))->toBe(['001', '002', '003', '004']);
    });

    it('returns a LazyCollection that yields individual records on demand', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        mockAccountDescribe();

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize'      => 4,
                'done'           => false,
                'nextRecordsUrl' => '/services/data/v64.0/query/01gxx-lazy',
                'records'        => [
                    ['Id' => '001', 'Name' => 'A', 'attributes' => ['type' => 'Account']],
                    ['Id' => '002', 'Name' => 'B', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        Forrest::shouldReceive('next')
            ->andReturn([
                'totalSize' => 4,
                'done'      => true,
                'records'   => [
                    ['Id' => '003', 'Name' => 'C', 'attributes' => ['type' => 'Account']],
                    ['Id' => '004', 'Name' => 'D', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        // Eloquent's cursor() wraps the underlying generator in a LazyCollection
        $cursor = Account::cursor();

        expect($cursor)->toBeInstanceOf(LazyCollection::class);

        // Confirm records are accessible without loading everything into memory at once
        $first = $cursor->first();
        expect($first->Id)->toBe('001');
    });

    it('uses queryAll when withTrashed is applied to cursor', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        mockAccountDescribe();

        // withTrashed swaps the connection to queryAll=true, so queryAll() is called
        Forrest::shouldReceive('queryAll')
            ->once()
            ->andReturn([
                'totalSize'      => 3,
                'done'           => false,
                'nextRecordsUrl' => '/services/data/v64.0/queryAll/01gxx-all',
                'records'        => [
                    ['Id' => '001', 'Name' => 'Active',  'IsDeleted' => false, 'attributes' => ['type' => 'Account']],
                    ['Id' => '002', 'Name' => 'Deleted', 'IsDeleted' => true,  'attributes' => ['type' => 'Account']],
                ],
            ]);

        Forrest::shouldReceive('next')
            ->once()
            ->with('/services/data/v64.0/queryAll/01gxx-all')
            ->andReturn([
                'totalSize' => 3,
                'done'      => true,
                'records'   => [
                    ['Id' => '003', 'Name' => 'Another Deleted', 'IsDeleted' => true, 'attributes' => ['type' => 'Account']],
                ],
            ]);

        // query() must not be called — queryAll() takes over
        Forrest::shouldNotReceive('query');

        $yielded = [];
        foreach (Account::withTrashed()->cursor() as $record) {
            $yielded[] = $record;
        }

        expect($yielded)->toHaveCount(3);
        expect(array_column($yielded, 'Id'))->toBe(['001', '002', '003']);
    });

});

// ---------------------------------------------------------------------------
// executeQuery() exception handling
// ---------------------------------------------------------------------------

describe('executeQuery() exception handling', function () {

    it('returns an empty collection when a query throws and throw_exceptions is false', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        mockAccountDescribe();

        // The adapter wraps Forrest exceptions in SalesforceException before
        // they bubble up to executeQuery()'s catch block
        Forrest::shouldReceive('query')
            ->once()
            ->andThrow(new Exception('INVALID_FIELD: No such column Name on entity Account'));

        // Suppress exception re-throw; executeQuery() must return []
        config(['eloquent-salesforce-objects.throw_exceptions' => false]);

        // Disable logging so the test does not write noise to the log
        config(['eloquent-salesforce-objects.logging_channel' => false]);

        $results = Account::get();

        expect($results)->toBeEmpty();
    });

    it('throws the exception when throw_exceptions is true', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        mockAccountDescribe();

        Forrest::shouldReceive('query')
            ->once()
            ->andThrow(new Exception('MALFORMED_QUERY: unexpected token'));

        config(['eloquent-salesforce-objects.throw_exceptions' => true]);
        config(['eloquent-salesforce-objects.logging_channel' => false]);

        expect(fn () => Account::get())
            ->toThrow(Exception::class, 'MALFORMED_QUERY');
    });

    it('does not call next() after an exception on the initial query', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        mockAccountDescribe();

        Forrest::shouldReceive('query')
            ->once()
            ->andThrow(new Exception('QUERY_TIMEOUT'));

        Forrest::shouldNotReceive('next');

        config(['eloquent-salesforce-objects.throw_exceptions' => false]);
        config(['eloquent-salesforce-objects.logging_channel' => false]);

        $results = Account::get();

        expect($results)->toBeEmpty();
    });

});
