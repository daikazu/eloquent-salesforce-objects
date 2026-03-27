<?php

use Daikazu\EloquentSalesforceObjects\Database\SalesforceBatch;
use Daikazu\EloquentSalesforceObjects\Database\SalesforceBatchResult;
use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);
});

afterEach(function () {
    Mockery::close();
});

describe('SalesforceBatch::new()', function () {
    it('returns a SalesforceBatch instance', function () {
        expect(SalesforceBatch::new())->toBeInstanceOf(SalesforceBatch::class);
    });

    it('returns distinct instances on each call', function () {
        $a = SalesforceBatch::new();
        $b = SalesforceBatch::new();

        expect($a)->not->toBe($b);
    });
});

describe('empty batch', function () {
    it('returns a SalesforceBatchResult with no names when run with no queries', function () {
        $result = SalesforceBatch::new()->run();

        expect($result)->toBeInstanceOf(SalesforceBatchResult::class);
        expect($result->names())->toBe([]);
        expect($result->allSuccessful())->toBeTrue();
    });

    it('does not call the composite API when no queries are added', function () {
        Forrest::shouldNotReceive('composite');

        SalesforceBatch::new()->run();
    });
});

describe('add() with raw SOQL string', function () {
    it('accepts a raw SOQL string and includes it in the batch request', function () {
        $rawSoql = "SELECT Id, Name FROM Account WHERE Type = 'Customer'";

        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('composite')
            ->once()
            ->with('batch', Mockery::on(function (array $options) use ($rawSoql) {
                $requests = $options['body']['batchRequests'] ?? [];

                return count($requests) === 1
                    && $requests[0]['method'] === 'GET'
                    && str_contains($requests[0]['url'], urlencode($rawSoql));
            }))
            ->andReturn([
                'results' => [
                    ['statusCode' => 200, 'result' => ['records' => []]],
                ],
            ]);

        $result = SalesforceBatch::new()
            ->add('accounts', $rawSoql)
            ->run();

        expect($result->successful('accounts'))->toBeTrue();
    });

    it('builds the composite URL with the configured API version', function () {
        config()->set('forrest.version', 'v58.0');

        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('composite')
            ->once()
            ->with('batch', Mockery::on(function (array $options) {
                $url = $options['body']['batchRequests'][0]['url'] ?? '';

                return str_starts_with($url, 'v58.0/query?q=');
            }))
            ->andReturn([
                'results' => [
                    ['statusCode' => 200, 'result' => ['records' => []]],
                ],
            ]);

        SalesforceBatch::new()
            ->add('q', 'SELECT Id FROM Account')
            ->run();
    });
});

describe('add() with Eloquent builder', function () {
    it('extracts SOQL from an Account builder and sends it to the composite API', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Type'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        Forrest::shouldReceive('composite')
            ->once()
            ->with('batch', Mockery::on(function (array $options) {
                $url = $options['body']['batchRequests'][0]['url'] ?? '';

                return str_contains($url, urlencode('Account'))
                    && str_contains($url, urlencode("Type = 'Customer'"));
            }))
            ->andReturn([
                'results' => [
                    ['statusCode' => 200, 'result' => ['records' => []]],
                ],
            ]);

        $builder = Account::where('Type', 'Customer');

        $result = SalesforceBatch::new()
            ->add('accounts', $builder)
            ->run();

        expect($result->successful('accounts'))->toBeTrue();
    });

    it('returns $this for fluent chaining on add()', function () {
        $batch = SalesforceBatch::new();
        $returned = $batch->add('q', 'SELECT Id FROM Account');

        expect($returned)->toBe($batch);
    });
});

describe('run() happy path with raw queries', function () {
    it('returns collections for both queries when both succeed', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('composite')
            ->once()
            ->andReturn([
                'results' => [
                    [
                        'statusCode' => 200,
                        'result'     => [
                            'records' => [
                                ['Id' => '001xx000001', 'Name' => 'Acme Corp'],
                                ['Id' => '001xx000002', 'Name' => 'Globex'],
                            ],
                        ],
                    ],
                    [
                        'statusCode' => 200,
                        'result'     => [
                            'records' => [
                                ['Id' => '003xx000001', 'LastName' => 'Smith'],
                            ],
                        ],
                    ],
                ],
            ]);

        $result = SalesforceBatch::new()
            ->add('accounts', 'SELECT Id, Name FROM Account')
            ->add('contacts', 'SELECT Id, LastName FROM Contact')
            ->run();

        expect($result->get('accounts'))->toHaveCount(2);
        expect($result->get('contacts'))->toHaveCount(1);
        expect($result->allSuccessful())->toBeTrue();
    });

    it('hydrates raw query results as stdClass objects, not model instances', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('composite')
            ->once()
            ->andReturn([
                'results' => [
                    [
                        'statusCode' => 200,
                        'result'     => [
                            'records' => [
                                ['Id' => '001xx000001', 'Name' => 'Acme Corp'],
                            ],
                        ],
                    ],
                ],
            ]);

        $result = SalesforceBatch::new()
            ->add('accounts', 'SELECT Id, Name FROM Account')
            ->run();

        $first = $result->get('accounts')->first();

        expect($first)->toBeInstanceOf(stdClass::class);
        expect($first->Id)->toBe('001xx000001');
        expect($first->Name)->toBe('Acme Corp');
    });

    it('returns empty collection when a successful result has no records', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('composite')
            ->once()
            ->andReturn([
                'results' => [
                    ['statusCode' => 200, 'result' => ['records' => []]],
                ],
            ]);

        $result = SalesforceBatch::new()
            ->add('accounts', 'SELECT Id FROM Account WHERE Name = \'none\'')
            ->run();

        expect($result->get('accounts'))->toHaveCount(0);
        expect($result->successful('accounts'))->toBeTrue();
    });
});

describe('run() with model hydration', function () {
    it('returns Account model instances when an Eloquent builder query is used', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Type'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        Forrest::shouldReceive('composite')
            ->once()
            ->andReturn([
                'results' => [
                    [
                        'statusCode' => 200,
                        'result'     => [
                            'records' => [
                                ['Id' => '001xx000001', 'Name' => 'Acme Corp', 'Type' => 'Customer'],
                                ['Id' => '001xx000002', 'Name' => 'Globex', 'Type' => 'Customer'],
                            ],
                        ],
                    ],
                ],
            ]);

        $result = SalesforceBatch::new()
            ->add('accounts', Account::where('Type', 'Customer'))
            ->run();

        $records = $result->get('accounts');

        expect($records)->toHaveCount(2);
        expect($records->first())->toBeInstanceOf(Account::class);
        expect($records->first()->Name)->toBe('Acme Corp');
        expect($records->last())->toBeInstanceOf(Account::class);
    });

    it('does not return stdClass for builder queries', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        Forrest::shouldReceive('composite')
            ->once()
            ->andReturn([
                'results' => [
                    [
                        'statusCode' => 200,
                        'result'     => [
                            'records' => [
                                ['Id' => '001xx000001', 'Name' => 'Acme Corp'],
                            ],
                        ],
                    ],
                ],
            ]);

        $result = SalesforceBatch::new()
            ->add('accounts', Account::query())
            ->run();

        $first = $result->get('accounts')->first();

        expect($first)->not->toBeInstanceOf(stdClass::class);
        expect($first)->toBeInstanceOf(Account::class);
    });
});

describe('run() partial failure', function () {
    it('marks the first query successful and the second failed when statusCode is 400', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('composite')
            ->once()
            ->andReturn([
                'results' => [
                    [
                        'statusCode' => 200,
                        'result'     => [
                            'records' => [
                                ['Id' => '001xx000001', 'Name' => 'Acme Corp'],
                            ],
                        ],
                    ],
                    [
                        'statusCode' => 400,
                        'result'     => [
                            ['message' => 'MALFORMED_QUERY: unexpected token', 'errorCode' => 'MALFORMED_QUERY'],
                        ],
                    ],
                ],
            ]);

        $result = SalesforceBatch::new()
            ->add('accounts', 'SELECT Id, Name FROM Account')
            ->add('bad_query', 'SELECT FROM Contact')
            ->run();

        expect($result->successful('accounts'))->toBeTrue();
        expect($result->get('accounts'))->toHaveCount(1);

        expect($result->failed('bad_query'))->toBeTrue();
        expect($result->get('bad_query'))->toBeNull();

        expect($result->allSuccessful())->toBeFalse();
        expect($result->failures())->toBe(['bad_query']);
    });

    it('captures the error payload from a failed batch result', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('composite')
            ->once()
            ->andReturn([
                'results' => [
                    [
                        'statusCode' => 400,
                        'result'     => [
                            ['message' => 'MALFORMED_QUERY', 'errorCode' => 'MALFORMED_QUERY'],
                        ],
                    ],
                ],
            ]);

        $result = SalesforceBatch::new()
            ->add('bad_query', 'INVALID SOQL')
            ->run();

        $error = $result->error('bad_query');

        expect($error)->toBeArray();
        expect($error)->toHaveKey('message');
    });
});

describe('run() total HTTP failure', function () {
    it('marks all queries as failed when the composite API call throws an exception', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('composite')
            ->once()
            ->andThrow(new RuntimeException('Connection timeout'));

        $result = SalesforceBatch::new()
            ->add('accounts', 'SELECT Id FROM Account')
            ->add('contacts', 'SELECT Id FROM Contact')
            ->run();

        expect($result->failed('accounts'))->toBeTrue();
        expect($result->failed('contacts'))->toBeTrue();
        expect($result->allSuccessful())->toBeFalse();
        expect($result->failures())->toBe(['accounts', 'contacts']);
    });

    it('stores the exception message in the error payload for each failed query', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('composite')
            ->once()
            ->andThrow(new RuntimeException('Connection timeout'));

        $result = SalesforceBatch::new()
            ->add('accounts', 'SELECT Id FROM Account')
            ->run();

        expect($result->error('accounts'))->toBe(['message' => 'Connection timeout']);
    });
});

describe('run() with null or missing batch result', function () {
    it('marks a query as failed when its index is missing from the response results', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        // Response only contains one result, but two queries were added
        Forrest::shouldReceive('composite')
            ->once()
            ->andReturn([
                'results' => [
                    [
                        'statusCode' => 200,
                        'result'     => ['records' => [['Id' => '001xx000001']]],
                    ],
                    // index 1 is intentionally absent
                ],
            ]);

        $result = SalesforceBatch::new()
            ->add('accounts', 'SELECT Id FROM Account')
            ->add('contacts', 'SELECT Id FROM Contact')
            ->run();

        expect($result->successful('accounts'))->toBeTrue();
        expect($result->failed('contacts'))->toBeTrue();
        expect($result->get('contacts'))->toBeNull();
    });

    it('uses "Unknown batch error" fallback when the missing result is null', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('composite')
            ->once()
            ->andReturn([
                'results' => [], // empty — both indices missing
            ]);

        $result = SalesforceBatch::new()
            ->add('accounts', 'SELECT Id FROM Account')
            ->run();

        expect($result->failed('accounts'))->toBeTrue();
        expect($result->error('accounts'))->toBe(['message' => 'Unknown batch error']);
    });
});

describe('run() chunking', function () {
    it('calls composite twice when batch_size is 2 and three queries are added', function () {
        config()->set('eloquent-salesforce-objects.batch_size', 2);

        Forrest::shouldReceive('hasToken')->andReturn(true);

        // First chunk: accounts + contacts
        Forrest::shouldReceive('composite')
            ->once()
            ->with('batch', Mockery::on(function (array $options) {
                return count($options['body']['batchRequests']) === 2;
            }))
            ->andReturn([
                'results' => [
                    ['statusCode' => 200, 'result' => ['records' => [['Id' => '001xx000001']]]],
                    ['statusCode' => 200, 'result' => ['records' => [['Id' => '003xx000001']]]],
                ],
            ]);

        // Second chunk: leads
        Forrest::shouldReceive('composite')
            ->once()
            ->with('batch', Mockery::on(function (array $options) {
                return count($options['body']['batchRequests']) === 1;
            }))
            ->andReturn([
                'results' => [
                    ['statusCode' => 200, 'result' => ['records' => [['Id' => '00Qxx000001']]]],
                ],
            ]);

        $result = SalesforceBatch::new()
            ->add('accounts', 'SELECT Id FROM Account')
            ->add('contacts', 'SELECT Id FROM Contact')
            ->add('leads', 'SELECT Id FROM Lead')
            ->run();

        expect($result->names())->toBe(['accounts', 'contacts', 'leads']);
        expect($result->get('accounts'))->toHaveCount(1);
        expect($result->get('contacts'))->toHaveCount(1);
        expect($result->get('leads'))->toHaveCount(1);
        expect($result->allSuccessful())->toBeTrue();
    });

    it('caps batch_size at 25 even when config is set higher', function () {
        config()->set('eloquent-salesforce-objects.batch_size', 100);

        // Create 26 queries — if batch size were truly 100 only one composite call
        // would happen; but capped at 25 means two calls: one of 25, one of 1
        $batch = SalesforceBatch::new();
        for ($i = 1; $i <= 26; $i++) {
            $batch->add("q{$i}", "SELECT Id FROM Account WHERE Name = 'q{$i}'");
        }

        Forrest::shouldReceive('hasToken')->andReturn(true);

        // First call with 25 requests
        Forrest::shouldReceive('composite')
            ->once()
            ->with('batch', Mockery::on(fn (array $options) => count($options['body']['batchRequests']) === 25))
            ->andReturn([
                'results' => array_fill(0, 25, ['statusCode' => 200, 'result' => ['records' => []]]),
            ]);

        // Second call with the remaining 1 request
        Forrest::shouldReceive('composite')
            ->once()
            ->with('batch', Mockery::on(fn (array $options) => count($options['body']['batchRequests']) === 1))
            ->andReturn([
                'results' => [
                    ['statusCode' => 200, 'result' => ['records' => []]],
                ],
            ]);

        $result = $batch->run();

        expect($result->names())->toHaveCount(26);
        expect($result->allSuccessful())->toBeTrue();
    });

    it('isolates chunk failures so a Throwable in one chunk does not affect another', function () {
        config()->set('eloquent-salesforce-objects.batch_size', 2);

        Forrest::shouldReceive('hasToken')->andReturn(true);

        // First chunk succeeds
        Forrest::shouldReceive('composite')
            ->once()
            ->with('batch', Mockery::on(fn (array $options) => count($options['body']['batchRequests']) === 2))
            ->andReturn([
                'results' => [
                    ['statusCode' => 200, 'result' => ['records' => [['Id' => '001xx000001']]]],
                    ['statusCode' => 200, 'result' => ['records' => [['Id' => '003xx000001']]]],
                ],
            ]);

        // Second chunk throws
        Forrest::shouldReceive('composite')
            ->once()
            ->with('batch', Mockery::on(fn (array $options) => count($options['body']['batchRequests']) === 1))
            ->andThrow(new RuntimeException('Network error on second chunk'));

        $result = SalesforceBatch::new()
            ->add('accounts', 'SELECT Id FROM Account')
            ->add('contacts', 'SELECT Id FROM Contact')
            ->add('leads', 'SELECT Id FROM Lead')
            ->run();

        expect($result->successful('accounts'))->toBeTrue();
        expect($result->successful('contacts'))->toBeTrue();
        expect($result->failed('leads'))->toBeTrue();
        expect($result->error('leads'))->toBe(['message' => 'Network error on second chunk']);
        expect($result->allSuccessful())->toBeFalse();
    });
});
