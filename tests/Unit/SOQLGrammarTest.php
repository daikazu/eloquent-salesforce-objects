<?php

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

// ---------------------------------------------------------------------------
// Shared helper — returns a minimal Account describe response
// ---------------------------------------------------------------------------

function accountDescribe(array $extraFields = []): array
{
    $base = [
        ['name' => 'Id'],
        ['name' => 'Name'],
        ['name' => 'Industry'],
        ['name' => 'CreatedDate'],
        ['name' => 'LastModifiedDate'],
        ['name' => 'IsDeleted'],
    ];

    return ['fields' => array_merge($base, $extraFields)];
}

// ===========================================================================
// SOQLGrammar
// ===========================================================================

describe('SOQLGrammar — NOT LIKE operator', function () {
    it('wraps NOT LIKE in SOQL (not … like …) syntax', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::where('Name', 'not like', '%Test%')->toSql();

        // SOQL requires: (not Name like '%Test%')
        expect($sql)->toContain('(not Name like');
        expect($sql)->toContain('%Test%');
        expect($sql)->not->toContain('not like');
    });

    it('does not affect regular LIKE queries', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::where('Name', 'like', 'Acme%')->toSql();

        expect($sql)->toContain('Name like');
        expect($sql)->not->toContain('(not');
    });
});

describe('SOQLGrammar — SOQL date literals', function () {
    it('emits simple date literals without quotes', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::where('CreatedDate', '>', 'TODAY')->toSql();

        // The literal should appear unquoted
        expect($sql)->toContain('TODAY');
        expect($sql)->not->toContain("'TODAY'");
    });

    it('emits YESTERDAY literal without quotes', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::where('CreatedDate', '>=', 'YESTERDAY')->toSql();

        expect($sql)->toContain('YESTERDAY');
        expect($sql)->not->toContain("'YESTERDAY'");
    });

    it('emits LAST_N_DAYS:30 literal without quotes', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::where('CreatedDate', '>', 'LAST_N_DAYS:30')->toSql();

        expect($sql)->toContain('LAST_N_DAYS:30');
        expect($sql)->not->toContain("'LAST_N_DAYS");
    });

    it('emits NEXT_N_DAYS:7 literal without quotes', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::where('CreatedDate', '<', 'NEXT_N_DAYS:7')->toSql();

        expect($sql)->toContain('NEXT_N_DAYS:7');
        expect($sql)->not->toContain("'NEXT_N_DAYS");
    });

    it('emits LAST_N_WEEKS:2 literal without quotes', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::where('CreatedDate', '>', 'LAST_N_WEEKS:2')->toSql();

        expect($sql)->toContain('LAST_N_WEEKS:2');
        expect($sql)->not->toContain("'LAST_N_WEEKS");
    });

    it('treats literals with colon as string literals by splitting on the colon prefix', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        // LAST_N_MONTHS:6 — the prefix "LAST_N_MONTHS" is in the literals list
        $sql = Account::where('CreatedDate', '>=', 'LAST_N_MONTHS:6')->toSql();

        expect($sql)->toContain('LAST_N_MONTHS:6');
        expect($sql)->not->toContain("'LAST_N_MONTHS");
    });

    it('does not treat arbitrary strings as date literals', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        // A plain string value should be quoted, not treated as a literal
        $sql = Account::where('Name', '=', 'Acme Corp')->toSql();

        expect($sql)->toContain("'Acme Corp'");
    });
});

// ===========================================================================
// SOQLBuilder
// ===========================================================================

describe('SOQLBuilder — withTrashed()', function () {
    it('generates valid SOQL without adding an IsDeleted WHERE filter', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::withTrashed()->toSql();

        expect($sql)->toContain('select');
        expect($sql)->toContain('from Account');
        // withTrashed only switches the connection to queryAll=true; it does
        // NOT add a WHERE clause — the query must not contain a "where" keyword
        expect($sql)->not->toContain('where');
    });

    it('executes query using queryAll endpoint', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        // queryAll is used by the connection; Forrest::queryAll should be called
        Forrest::shouldReceive('queryAll')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Archived Co', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $results = Account::withTrashed()->get();

        expect($results)->toHaveCount(1);
        expect($results[0]->Name)->toBe('Archived Co');
    });
});

describe('SOQLBuilder — onlyTrashed()', function () {
    it('adds WHERE IsDeleted = true to the SOQL', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::onlyTrashed()->toSql();

        expect($sql)->toContain('IsDeleted');
        // The boolean true clause is compiled as "IsDeleted = ?"  with binding TRUE
        // SOQLGrammar.whereBoolean produces: column = ? (binding resolved to 1/true)
        expect($sql)->toContain('from Account');
        expect($sql)->toContain('where');
    });

    it('executes query using queryAll endpoint and returns only deleted records', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        Forrest::shouldReceive('queryAll')
            ->once()
            ->with(Mockery::on(fn ($q) => str_contains($q, 'IsDeleted')))
            ->andReturn([
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Deleted Co 1', 'IsDeleted' => true, 'attributes' => ['type' => 'Account']],
                    ['Id' => '001xx000002', 'Name' => 'Deleted Co 2', 'IsDeleted' => true, 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $results = Account::onlyTrashed()->get();

        expect($results)->toHaveCount(2);
    });
});

describe('SOQLBuilder — whereColumn()', function () {
    it('throws InvalidArgumentException because SOQL does not support column comparisons', function () {
        expect(fn () => Account::query()->whereColumn('Name', 'Industry'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('includes a helpful message about semi-join alternatives', function () {
        $caught = null;

        try {
            Account::query()->whereColumn('Name', '=', 'Industry');
        } catch (InvalidArgumentException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull();
        expect($caught->getMessage())->toContain('SOQL');
    });
});

describe('SOQLBuilder — allColumns()', function () {
    it('sets shouldIgnoreDefaults and toSql() does not restrict to defaultColumns', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Return a describe with fields beyond what Account's defaultColumns lists
        Forrest::shouldReceive('describe')->with('Account')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'Type'],
                ['name' => 'CustomField__c'],
                ['name' => 'AnotherCustom__c'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::allColumns()->toSql();

        // When defaultColumns are ignored, the describe() call returns all fields
        // including CustomField__c which is not in Account::$defaultColumns
        expect($sql)->toContain('CustomField__c');
        expect($sql)->toContain('AnotherCustom__c');
    });

    it('is chainable with other query methods', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn([
            'fields' => [
                ['name' => 'Id'],
                ['name' => 'Name'],
                ['name' => 'ExtraField__c'],
                ['name' => 'CreatedDate'],
                ['name' => 'LastModifiedDate'],
                ['name' => 'IsDeleted'],
            ],
        ]);

        $sql = Account::allColumns()->where('Name', 'like', 'Acme%')->toSql();

        expect($sql)->toContain('ExtraField__c');
        expect($sql)->toContain('Name like');
        expect($sql)->toContain('Acme%');
    });
});

describe('SOQLBuilder — cursor() with defaultColumns', function () {
    it('uses defaultColumns when no explicit columns are specified', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe([
            ['name' => 'Type'],
            ['name' => 'Website'],
        ]));

        // cursor() calls SOQLConnection::cursor() which calls Forrest::query (or queryAll)
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($q) {
                // Should include Name (from defaultColumns) and Id (auto-added)
                return str_contains($q, 'Name') && str_contains($q, 'Id');
            }))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000001', 'Name' => 'Cursor Co', 'attributes' => ['type' => 'Account']],
                ],
            ]);

        $items = [];
        foreach (Account::cursor() as $item) {
            $items[] = $item;
        }

        expect($items)->toHaveCount(1);
        expect($items[0]->Name)->toBe('Cursor Co');
    });

    it('ensures Id is always included in cursor defaultColumns', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(fn ($q) => str_contains($q, 'Id')))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        // Consuming the generator triggers the query
        iterator_to_array(Account::cursor());
    });

    it('skips defaultColumns when explicit select is applied', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($q) {
                // explicit select(['Id', 'Name']) — defaultColumns path should be skipped
                return str_contains($q, 'select Id, Name');
            }))
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        iterator_to_array(Account::select(['Id', 'Name'])->cursor());
    });
});
