<?php

use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Database\SOQLConnection;
use Daikazu\EloquentSalesforceObjects\Database\SOQLGrammar;
use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Examples\Contact;
use Illuminate\Database\Query\Builder;
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

    it('suggests a whereIn semi-join rather than unsupported whereHas/has', function () {
        $caught = null;

        try {
            Account::query()->whereColumn('Name', '=', 'Industry');
        } catch (InvalidArgumentException $e) {
            $caught = $e;
        }

        expect($caught->getMessage())
            ->toContain("whereIn('Id'")
            ->not->toContain('Use relationship constraints');
    });
});

describe('SOQLBuilder — relationship existence queries', function () {
    it('throws for whereHas() because it compiles to a column comparison', function () {
        expect(fn () => Account::whereHas('contacts', fn ($q) => $q->where('Email', 'x'))->get(['Id']))
            ->toThrow(InvalidArgumentException::class);
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

    it('includes the timestamp and soft-delete columns, like get() does', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(fn ($q) => str_starts_with($q, 'select Id, Name, ')
                && str_contains($q, ', CreatedDate, LastModifiedDate, IsDeleted from Account')))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        iterator_to_array(Account::cursor());
    });

    it('expands * to every field for a model without defaultColumns', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Contact')->andReturn([
            'fields' => [['name' => 'Id'], ['name' => 'LastName']],
        ]);

        Forrest::shouldReceive('query')
            ->once()
            ->with('select Id, CreatedDate, LastModifiedDate, IsDeleted, LastName from Contact')
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        iterator_to_array(Contact::cursor());
    });

    it('expands * to every field after allColumns()', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        Forrest::shouldReceive('query')
            ->once()
            ->with('select Id, CreatedDate, LastModifiedDate, IsDeleted, Name, Industry from Account')
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        iterator_to_array(Account::allColumns()->cursor());
    });
});

// ===========================================================================
// SOQLGrammar — compileAggregate
// ===========================================================================

describe('SOQLGrammar — compileAggregate with COUNT(*)', function () {
    it('compiles COUNT() with no argument when column is wildcard', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        // count() calls aggregate('count', ['*']) which issues a Forrest::query call.
        // We capture the SOQL string that reaches Forrest to verify grammar output.
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function (string $soql): bool {
                // SOQL: select COUNT() from Account
                return str_contains($soql, 'COUNT()');
            }))
            ->andReturn([
                'totalSize' => 5,
                'done'      => true,
                'records'   => [],
            ]);

        $result = Account::count();

        expect($result)->toBe(5);
    });

    it('uses COUNT(Id) for non-wildcard aggregate when * is specified for non-count aggregates', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        // max() with '*' column should fall back to max(Id) per grammar logic
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function (string $soql): bool {
                return str_contains(strtoupper($soql), 'MAX(Id)') || str_contains($soql, 'MAX(Id)');
            }))
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [['expr0' => '001xx000003']],
            ]);

        Account::max('*');
    });
});

describe('SOQLGrammar — compileAggregate with distinct', function () {
    it('compiles a distinct count of a column to COUNT_DISTINCT(…)', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function (string $soql): bool {
                return $soql === 'select COUNT_DISTINCT(Name) from Account';
            }))
            ->andReturn([
                'totalSize' => 3,
                'done'      => true,
                'records'   => [['expr0' => 3]],
            ]);

        Account::distinct()->count('Name');
    });

    it('does not prepend distinct when column is empty (COUNT with wildcard)', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        // distinct()->count() still uses COUNT() with no argument — distinct on * is a no-op
        // per grammar: $column === '' after * is resolved, so distinct branch is skipped
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function (string $soql): bool {
                return str_contains($soql, 'COUNT()') && ! str_contains($soql, 'distinct');
            }))
            ->andReturn([
                'totalSize' => 7,
                'done'      => true,
                'records'   => [],
            ]);

        Account::distinct()->count('*');
    });
});

// ===========================================================================
// SOQLGrammar — compileLock
// ===========================================================================

describe('SOQLGrammar — locking', function () {
    beforeEach(function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn(accountDescribe());
    });

    // Verified against a real org: "select Id from Account limit 1 for update" is
    // rejected with MALFORMED_QUERY; row locking is Apex-only.
    it('throws for lockForUpdate() and sharedLock(), since the API has no row locking', function (string $method) {
        expect(fn () => Account::select(['Id'])->{$method}()->toSql())
            ->toThrow(InvalidArgumentException::class, 'Row locking (FOR UPDATE) is only available in Apex');
    })->with(['lockForUpdate', 'sharedLock']);

    it('passes FOR VIEW and FOR REFERENCE through', function (string $clause) {
        expect(Account::select(['Id'])->lock($clause)->toSql())->toBe("select Id from Account {$clause}");
    })->with(['FOR VIEW', 'FOR REFERENCE']);

    it('throws for any other lock string', function () {
        expect(fn () => Account::select(['Id'])->lock('LOCK IN SHARE MODE')->toSql())
            ->toThrow(InvalidArgumentException::class, 'FOR VIEW or FOR REFERENCE');
    });
});

// ===========================================================================
// SOQLGrammar — whereIn with empty values
// ===========================================================================

describe('SOQLGrammar — whereIn with empty values', function () {
    it('compiles to Id = null when an empty array is passed', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::whereIn('Id', [])->toSql();

        expect($sql)->toContain('Id = null');
    });

    it('does not produce an IN () clause for an empty array', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::whereIn('Id', [])->toSql();

        expect($sql)->not->toContain(' in (');
        expect($sql)->not->toContain('in ()');
    });

    it('produces a normal IN clause when values are present', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        $sql = Account::whereIn('Id', ['001xx000001', '001xx000002'])->toSql();

        expect($sql)->toContain(' in (');
        expect($sql)->not->toContain('Id = null');
    });

    it('produces Id = null for a non-Id column with an empty array', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(accountDescribe());

        // Grammar hard-codes 'Id = null' regardless of which column is used
        $sql = Account::whereIn('Industry', [])->toSql();

        expect($sql)->toContain('Id = null');
    });
});

// ===========================================================================
// SOQLBuilder — join() is not supported by SOQL
// ===========================================================================

describe('SOQLBuilder — join()', function () {
    it('throws for every join variant, pointing to with() and parent fields', function (string $method, array $args) {
        expect(fn () => Account::query()->{$method}(...$args))
            ->toThrow(InvalidArgumentException::class, 'SOQL does not support joins');
    })->with([
        'join'          => ['join', ['Contact', 'Contact.AccountId', '=', 'Account.Id']],
        'leftJoin'      => ['leftJoin', ['Contact', 'Contact.AccountId', '=', 'Account.Id']],
        'rightJoin'     => ['rightJoin', ['Contact', 'Contact.AccountId', '=', 'Account.Id']],
        'crossJoin'     => ['crossJoin', ['Contact']],
        'joinWhere'     => ['joinWhere', ['Contact', 'Contact.Name', '=', 'x']],
        'leftJoinWhere' => ['leftJoinWhere', ['Contact', 'Contact.Name', '=', 'x']],
    ]);

    it('throws when a join added to the base query reaches the grammar', function () {
        $connection = new SOQLConnection(Mockery::mock(AdapterInterface::class));
        $grammar = new SOQLGrammar($connection);
        $connection->setGrammar($grammar);

        $query = (new Builder($connection, $grammar))
            ->from('Account')
            ->join('Contact', 'Contact.AccountId', '=', 'Account.Id');

        expect(fn () => $query->toSql())
            ->toThrow(InvalidArgumentException::class, 'SOQL does not support joins');
    });

    it('still forwards other query builder methods', function () {
        expect(Account::query()->whereIn('Id', ['001'])->getQuery()->wheres)->toHaveCount(1);
    });
});

// ===========================================================================
// SQL-only constructs: compile to SOQL equivalents, or throw a clear error
// ===========================================================================

describe('SOQLGrammar — SQL-only constructs', function () {
    beforeEach(function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn(accountDescribe());
    });

    it('renders null inside whereIn as the SOQL null literal', function () {
        expect(Account::select(['Id'])->whereIn('Name', ['a', null])->toSql())
            ->toBe("select Id from Account where Name in ('a', null)");
    });

    it('sends null inside whereIn as null in the executed query', function () {
        Forrest::shouldReceive('query')->once()
            ->with("select Id from Account where Name not in ('a', null)")
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Account::select(['Id'])->whereNotIn('Name', ['a', null])->get();
    });

    it('compiles an empty whereNotIn to a condition that is always true', function () {
        expect(Account::select(['Id'])->whereNotIn('Name', [])->toSql())
            ->toBe('select Id from Account where Id != null');
    });

    it('compiles empty integer in/not-in lists to SOQL', function () {
        expect(Account::select(['Id'])->whereIntegerInRaw('NumberOfEmployees', [])->toSql())
            ->toBe('select Id from Account where Id = null');
        expect(Account::select(['Id'])->whereIntegerNotInRaw('NumberOfEmployees', [])->toSql())
            ->toBe('select Id from Account where Id != null');
        expect(Account::select(['Id'])->whereIntegerInRaw('NumberOfEmployees', [1, 2])->toSql())
            ->toBe('select Id from Account where NumberOfEmployees in (1, 2)');
    });

    it('compiles whereBetween to a pair of comparisons', function () {
        expect(Account::select(['Id'])->whereBetween('AnnualRevenue', [1, 5])->toSql())
            ->toBe('select Id from Account where (AnnualRevenue >= 1 and AnnualRevenue <= 5)');
        expect(Account::select(['Id'])->whereBetween('Name', ['a', 'm'])->toSql())
            ->toBe("select Id from Account where (Name >= 'a' and Name <= 'm')");
    });

    it('compiles whereNotBetween and orWhereBetween', function () {
        expect(Account::select(['Id'])->whereNotBetween('AnnualRevenue', [1, 5])->toSql())
            ->toBe('select Id from Account where (AnnualRevenue < 1 or AnnualRevenue > 5)');
        expect(Account::select(['Id'])->where('Name', 'x')->orWhereBetween('AnnualRevenue', [1, 5])->toSql())
            ->toBe("select Id from Account where Name = 'x' or (AnnualRevenue >= 1 and AnnualRevenue <= 5)");
    });

    it('leaves datetime values in whereBetween unquoted', function () {
        $sql = Account::select(['Id'])->whereBetween('CreatedDate', [
            new DateTimeImmutable('2024-01-01T00:00:00Z'),
            new DateTimeImmutable('2024-02-01T00:00:00Z'),
        ])->toSql();

        expect($sql)->toBe('select Id from Account where (CreatedDate >= 2024-01-01T00:00:00Z and CreatedDate <= 2024-02-01T00:00:00Z)');
    });

    it('throws for whereBetweenColumns, since SOQL cannot compare columns', function () {
        expect(fn () => Account::select(['Id'])->whereBetweenColumns('AnnualRevenue', ['Min__c', 'Max__c'])->toSql())
            ->toThrow(InvalidArgumentException::class, 'SOQL does not support column-to-column comparisons');
    });

    it('throws for inRandomOrder()', function () {
        expect(fn () => Account::select(['Id'])->inRandomOrder()->toSql())
            ->toThrow(InvalidArgumentException::class, 'SOQL has no random ordering');
    });

    it('throws for distinct()', function () {
        expect(fn () => Account::select(['Name'])->distinct()->toSql())
            ->toThrow(InvalidArgumentException::class, 'SOQL has no DISTINCT');
    });

    it('compiles a distinct count to COUNT_DISTINCT', function () {
        Forrest::shouldReceive('query')->once()
            ->with('select COUNT_DISTINCT(Industry) from Account')
            ->andReturn(['totalSize' => 1, 'done' => true, 'records' => [['expr0' => 7]]]);

        expect(Account::distinct()->count('Industry'))->toBe(7);
    });
});

describe('SOQLGrammar — more SQL-only constructs', function () {
    beforeEach(function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->andReturn(accountDescribe());
    });

    it('throws for inOrderOf(), since SOQL has no CASE expressions', function () {
        expect(fn () => Account::select(['Id'])->inOrderOf('Industry', ['Tech', 'Retail'])->toSql())
            ->toThrow(InvalidArgumentException::class, 'SOQL cannot order by a list of values');
    });

    it('compiles havingBetween() to a pair of comparisons', function () {
        expect(Account::select(['Industry'])->groupBy('Industry')->havingBetween('COUNT(Id)', [2, 10])->toSql())
            ->toBe('select Industry from Account group by Industry having (COUNT(Id) >= 2 and COUNT(Id) <= 10)');
        expect(Account::select(['Industry'])->groupBy('Industry')->havingNotBetween('COUNT(Id)', [2, 10])->toSql())
            ->toBe('select Industry from Account group by Industry having (COUNT(Id) < 2 or COUNT(Id) > 10)');
    });

    it('throws a clear error for writes SOQL has no form of', function (Closure $call, string $message) {
        Forrest::shouldReceive('sobjects')->never();
        Forrest::shouldReceive('post')->never();

        expect($call)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'insertOrIgnore'        => [fn () => Account::insertOrIgnore([['Name' => 'A']]), 'no insert-or-ignore'],
        'fillAndInsertOrIgnore' => [fn () => Account::fillAndInsertOrIgnore([['Name' => 'A']]), 'no insert-or-ignore'],
        'insertUsing'           => [fn () => Account::query()->insertUsing(['Name'], 'select Name from Lead'), 'cannot insert from a query'],
        'updateOrInsert'        => [fn () => Account::query()->updateOrInsert(['Name' => 'A'], ['Rating' => 'Hot']), 'updateOrCreate()'],
        'saveOrIgnore'          => [fn () => (new Account(['Name' => 'A']))->saveOrIgnore(), 'no insert-or-ignore'],
    ]);
});
