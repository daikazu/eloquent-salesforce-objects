<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Illuminate\Support\Facades\Cache;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);

    Forrest::shouldReceive('hasToken')->andReturn(true);

    $this->adapter = app(SalesforceAdapter::class);
});

afterEach(function () {
    Mockery::close();
});

// ---------------------------------------------------------------------------
// query
// ---------------------------------------------------------------------------

describe('query', function () {
    it('returns parsed records on success', function () {
        Forrest::shouldReceive('query')
            ->once()
            ->with('SELECT Id FROM Account')
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['attributes' => ['type' => 'Account'], 'Id' => '001xx000003DGb2AAG'],
                ],
            ]);

        $result = $this->adapter->query('SELECT Id FROM Account');

        expect($result)->toHaveKeys(['records', 'totalSize', 'done', 'nextRecordsUrl']);
        expect($result['records'])->toHaveCount(1);
        expect($result['records'][0])->toHaveKey('Id', '001xx000003DGb2AAG');
        expect($result['records'][0])->not->toHaveKey('attributes');
        expect($result['totalSize'])->toBe(1);
        expect($result['done'])->toBeTrue();
    });

    it('wraps thrown exception in SalesforceException with correct prefix', function () {
        $original = new RuntimeException('API error');
        Forrest::shouldReceive('query')->once()->andThrow($original);

        expect(fn () => $this->adapter->query('SELECT Id FROM Account'))
            ->toThrow(SalesforceException::class, 'Query failed: API error');
    });

    it('preserves original exception as previous', function () {
        $original = new RuntimeException('API error');
        Forrest::shouldReceive('query')->once()->andThrow($original);

        try {
            $this->adapter->query('SELECT Id FROM Account');
        } catch (SalesforceException $e) {
            expect($e->getPrevious())->toBe($original);
        }
    });
});

// ---------------------------------------------------------------------------
// queryAll
// ---------------------------------------------------------------------------

describe('queryAll', function () {
    it('returns parsed records including soft-deleted rows on success', function () {
        Forrest::shouldReceive('queryAll')
            ->once()
            ->with('SELECT Id FROM Account')
            ->andReturn([
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    ['attributes' => ['type' => 'Account'], 'Id' => '001A'],
                    ['attributes' => ['type' => 'Account'], 'Id' => '001B'],
                ],
            ]);

        $result = $this->adapter->queryAll('SELECT Id FROM Account');

        expect($result['records'])->toHaveCount(2);
        expect($result['totalSize'])->toBe(2);
    });

    it('wraps thrown exception in SalesforceException with correct prefix', function () {
        $original = new RuntimeException('timeout');
        Forrest::shouldReceive('queryAll')->once()->andThrow($original);

        expect(fn () => $this->adapter->queryAll('SELECT Id FROM Account'))
            ->toThrow(SalesforceException::class, 'QueryAll failed: timeout');
    });

    it('preserves original exception as previous', function () {
        $original = new RuntimeException('timeout');
        Forrest::shouldReceive('queryAll')->once()->andThrow($original);

        try {
            $this->adapter->queryAll('SELECT Id FROM Account');
        } catch (SalesforceException $e) {
            expect($e->getPrevious())->toBe($original);
        }
    });
});

// ---------------------------------------------------------------------------
// next
// ---------------------------------------------------------------------------

describe('next', function () {
    it('returns the next page of records on success', function () {
        $nextUrl = '/services/data/v64.0/query/01gxx000003DGb2-2000';

        Forrest::shouldReceive('next')
            ->once()
            ->with($nextUrl)
            ->andReturn([
                'totalSize' => 3000,
                'done'      => true,
                'records'   => [
                    ['attributes' => ['type' => 'Account'], 'Id' => '001C'],
                ],
            ]);

        $result = $this->adapter->next($nextUrl);

        expect($result['records'])->toHaveCount(1);
        expect($result['records'][0]['Id'])->toBe('001C');
        expect($result['done'])->toBeTrue();
    });

    it('wraps thrown exception in SalesforceException with correct prefix', function () {
        $original = new RuntimeException('not found');
        Forrest::shouldReceive('next')->once()->andThrow($original);

        expect(fn () => $this->adapter->next('/services/data/v64.0/query/token'))
            ->toThrow(SalesforceException::class, 'Next records query failed: not found');
    });

    it('preserves original exception as previous', function () {
        $original = new RuntimeException('not found');
        Forrest::shouldReceive('next')->once()->andThrow($original);

        try {
            $this->adapter->next('/services/data/v64.0/query/token');
        } catch (SalesforceException $e) {
            expect($e->getPrevious())->toBe($original);
        }
    });
});

// ---------------------------------------------------------------------------
// search
// ---------------------------------------------------------------------------

describe('search', function () {
    it('returns parsed search results on success', function () {
        $sosl = 'FIND {Acme} IN ALL FIELDS RETURNING Account(Id, Name)';

        Forrest::shouldReceive('search')
            ->once()
            ->with($sosl)
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['attributes' => ['type' => 'Account'], 'Id' => '001D', 'Name' => 'Acme Corp'],
                ],
            ]);

        $result = $this->adapter->search($sosl);

        expect($result['records'])->toHaveCount(1);
        expect($result['records'][0]['Name'])->toBe('Acme Corp');
        expect($result['records'][0])->not->toHaveKey('attributes');
    });

    it('wraps thrown exception in SalesforceException with correct prefix', function () {
        $original = new RuntimeException('SOSL parse error');
        Forrest::shouldReceive('search')->once()->andThrow($original);

        expect(fn () => $this->adapter->search('FIND {bad}'))
            ->toThrow(SalesforceException::class, 'Search failed: SOSL parse error');
    });

    it('preserves original exception as previous', function () {
        $original = new RuntimeException('SOSL parse error');
        Forrest::shouldReceive('search')->once()->andThrow($original);

        try {
            $this->adapter->search('FIND {bad}');
        } catch (SalesforceException $e) {
            expect($e->getPrevious())->toBe($original);
        }
    });
});

// ---------------------------------------------------------------------------
// retrieve
// ---------------------------------------------------------------------------

describe('retrieve', function () {
    it('fetches a record by object and id without fields', function () {
        Forrest::shouldReceive('get')
            ->once()
            ->with('sobjects/Account/001xx000003DGb2AAG')
            ->andReturn([
                'attributes' => ['type' => 'Account'],
                'Id'         => '001xx000003DGb2AAG',
                'Name'       => 'Acme',
            ]);

        $result = $this->adapter->retrieve('Account', '001xx000003DGb2AAG');

        expect($result)->toHaveKey('Id', '001xx000003DGb2AAG');
        expect($result)->toHaveKey('Name', 'Acme');
        expect($result)->not->toHaveKey('attributes');
    });

    it('includes a fields query string when fields are specified', function () {
        Forrest::shouldReceive('get')
            ->once()
            ->with('sobjects/Account/001xx000003DGb2AAG?fields=Id,Name')
            ->andReturn([
                'Id'   => '001xx000003DGb2AAG',
                'Name' => 'Acme',
            ]);

        $result = $this->adapter->retrieve('Account', '001xx000003DGb2AAG', ['Id', 'Name']);

        expect($result)->toHaveKey('Id', '001xx000003DGb2AAG');
        expect($result)->toHaveKey('Name', 'Acme');
    });

    it('wraps thrown exception in SalesforceException with object and id in message', function () {
        $original = new RuntimeException('not found');
        Forrest::shouldReceive('get')->once()->andThrow($original);

        expect(fn () => $this->adapter->retrieve('Account', '001xx000003DGb2AAG'))
            ->toThrow(SalesforceException::class, 'Retrieve failed for Account 001xx000003DGb2AAG: not found');
    });

    it('preserves original exception as previous', function () {
        $original = new RuntimeException('not found');
        Forrest::shouldReceive('get')->once()->andThrow($original);

        try {
            $this->adapter->retrieve('Account', '001xx000003DGb2AAG');
        } catch (SalesforceException $e) {
            expect($e->getPrevious())->toBe($original);
        }
    });
});

// ---------------------------------------------------------------------------
// upsert
// ---------------------------------------------------------------------------

describe('upsert', function () {
    it('calls Forrest::sobjects with patch method and correct path on success', function () {
        Forrest::shouldReceive('sobjects')
            ->once()
            ->with(
                'Account/External_Id__c/EXT-001',
                Mockery::on(fn ($opts) => $opts['method'] === 'patch' && $opts['body']['Name'] === 'Acme')
            )
            ->andReturn(['id' => '001xx000003DGb2AAG', 'success' => true, 'errors' => []]);

        $result = $this->adapter->upsert('Account', 'External_Id__c', 'EXT-001', ['Name' => 'Acme']);

        expect($result)->toHaveKey('id', '001xx000003DGb2AAG');
        expect($result['success'])->toBeTrue();
    });

    it('builds the path as {object}/{externalIdField}/{externalId}', function () {
        Forrest::shouldReceive('sobjects')
            ->once()
            ->with('Contact/MyExternalId__c/C-999', Mockery::any())
            ->andReturn(['id' => '003xx000004TGb1', 'success' => true, 'errors' => []]);

        $this->adapter->upsert('Contact', 'MyExternalId__c', 'C-999', ['LastName' => 'Doe']);
    });

    it('wraps thrown exception in SalesforceException with correct prefix', function () {
        $original = new RuntimeException('conflict');
        Forrest::shouldReceive('sobjects')->once()->andThrow($original);

        expect(fn () => $this->adapter->upsert('Account', 'External_Id__c', 'EXT-001', []))
            ->toThrow(SalesforceException::class, 'Upsert failed for Account: conflict');
    });

    it('preserves original exception as previous', function () {
        $original = new RuntimeException('conflict');
        Forrest::shouldReceive('sobjects')->once()->andThrow($original);

        try {
            $this->adapter->upsert('Account', 'External_Id__c', 'EXT-001', []);
        } catch (SalesforceException $e) {
            expect($e->getPrevious())->toBe($original);
        }
    });
});

// ---------------------------------------------------------------------------
// describeGlobal
// ---------------------------------------------------------------------------

describe('describeGlobal', function () {
    it('returns parsed metadata on success', function () {
        $rawResponse = [
            'sobjects' => [
                ['name' => 'Account', 'label' => 'Account'],
                ['name' => 'Contact', 'label' => 'Contact'],
            ],
        ];

        Forrest::shouldReceive('describe')
            ->once()
            ->withNoArgs()
            ->andReturn($rawResponse);

        $result = $this->adapter->describeGlobal();

        expect($result)->toHaveKey('sobjects');
        expect($result['sobjects'])->toHaveCount(2);
    });

    it('wraps thrown exception in SalesforceException with correct prefix', function () {
        $original = new RuntimeException('server error');
        Forrest::shouldReceive('describe')->once()->withNoArgs()->andThrow($original);

        expect(fn () => $this->adapter->describeGlobal())
            ->toThrow(SalesforceException::class, 'DescribeGlobal failed: server error');
    });

    it('preserves original exception as previous', function () {
        $original = new RuntimeException('server error');
        Forrest::shouldReceive('describe')->once()->withNoArgs()->andThrow($original);

        try {
            $this->adapter->describeGlobal();
        } catch (SalesforceException $e) {
            expect($e->getPrevious())->toBe($original);
        }
    });
});

// ---------------------------------------------------------------------------
// describe (with caching behaviour)
// ---------------------------------------------------------------------------

describe('describe', function () {
    it('returns parsed describe metadata for a plain object name', function () {
        $rawResponse = ['name' => 'Account', 'fields' => []];

        Forrest::shouldReceive('describe')
            ->once()
            ->with('Account')
            ->andReturn($rawResponse);

        // Instantiate directly to control the TTL without relying on the singleton
        $adapter = new Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
        $result = $adapter->describe('Account');

        expect($result)->toHaveKey('name', 'Account');
    });

    it('calls Forrest::describe only once when cache is enabled and same object is described twice', function () {
        Cache::flush();

        $rawResponse = ['name' => 'Account', 'fields' => [['name' => 'Id']]];

        // The singleton was built with metadata_cache_ttl = 86400 (default), so it caches
        Forrest::shouldReceive('describe')
            ->once()
            ->with('Account')
            ->andReturn($rawResponse);

        $first  = $this->adapter->describe('Account');
        $second = $this->adapter->describe('Account');

        expect($first)->toBe($second);
        expect($first['name'])->toBe('Account');
    });

    it('calls Forrest::describe twice when cache TTL is 0', function () {
        Cache::flush();

        $rawResponse = ['name' => 'Account', 'fields' => []];

        Forrest::shouldReceive('describe')
            ->twice()
            ->with('Account')
            ->andReturn($rawResponse);

        // Instantiate directly with TTL=0 so caching is disabled
        config(['eloquent-salesforce-objects.metadata_cache_ttl' => 0]);
        $adapter = new Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
        $adapter->describe('Account');
        $adapter->describe('Account');
    });

    it('bypasses cache when object is null', function () {
        $rawResponse = ['sobjects' => []];

        // Even with cache enabled (default singleton), null object skips Cache::remember
        Forrest::shouldReceive('describe')
            ->twice()
            ->with(null)
            ->andReturn($rawResponse);

        $this->adapter->describe(null);
        $this->adapter->describe(null);
    });

    it('resolves a model class string to the table name', function () {
        Cache::flush();

        // Account class has no explicit $table, so class_basename resolves to 'Account'
        $rawResponse = ['name' => 'Account', 'fields' => []];

        Forrest::shouldReceive('describe')
            ->once()
            ->with('Account')
            ->andReturn($rawResponse);

        $result = $this->adapter->describe(Account::class);

        expect($result['name'])->toBe('Account');
    });

    it('resolves a model instance to the table name', function () {
        Cache::flush();

        $rawResponse = ['name' => 'Account', 'fields' => []];

        Forrest::shouldReceive('describe')
            ->once()
            ->with('Account')
            ->andReturn($rawResponse);

        $result = $this->adapter->describe(new Account);

        expect($result['name'])->toBe('Account');
    });

    it('wraps thrown exception in SalesforceException with object name in message', function () {
        config(['eloquent-salesforce-objects.metadata_cache_ttl' => 0]);
        $adapter = new Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

        $original = new RuntimeException('object not found');
        Forrest::shouldReceive('describe')->once()->andThrow($original);

        expect(fn () => $adapter->describe('BadObject'))
            ->toThrow(SalesforceException::class, 'Describe failed for BadObject: object not found');
    });

    it('preserves original exception as previous', function () {
        config(['eloquent-salesforce-objects.metadata_cache_ttl' => 0]);
        $adapter = new Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

        $original = new RuntimeException('object not found');
        Forrest::shouldReceive('describe')->once()->andThrow($original);

        try {
            $adapter->describe('BadObject');
        } catch (SalesforceException $e) {
            expect($e->getPrevious())->toBe($original);
        }
    });

    it('uses object-specific cache keys so different objects are cached independently', function () {
        Cache::flush();

        // Both Account and Contact are described for the first time — two real API calls
        Forrest::shouldReceive('describe')
            ->once()
            ->with('Account')
            ->andReturn(['name' => 'Account', 'fields' => []]);

        Forrest::shouldReceive('describe')
            ->once()
            ->with('Contact')
            ->andReturn(['name' => 'Contact', 'fields' => []]);

        $accountResult = $this->adapter->describe('Account');
        $contactResult = $this->adapter->describe('Contact');

        expect($accountResult['name'])->toBe('Account');
        expect($contactResult['name'])->toBe('Contact');
    });
});
