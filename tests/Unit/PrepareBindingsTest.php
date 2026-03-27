<?php

/**
 * Tests for SOQLConnection::prepareBindings() and the full binding pipeline.
 *
 * Architecture notes:
 *  - prepareBindings() is called by prepare(), which is called from select() and cursor()
 *    during actual Salesforce query execution. It is NOT called by toSql().
 *  - SOQLBuilder::toSql() has its own binding substitution that only escapes single quotes
 *    via Str::replace(). It does not convert booleans or DateTimeInterface values.
 *  - To verify prepareBindings() in isolation, we instantiate SOQLConnection directly.
 *  - To verify the full execution pipeline (prepare → prepareBindings → executeQuery),
 *    we mock Forrest::query and assert the interpolated SOQL string that Salesforce receives.
 */

use Daikazu\EloquentSalesforceObjects\Database\SOQLConnection;
use Daikazu\EloquentSalesforceObjects\Database\SOQLGrammar;
use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Build a SOQLConnection with a real SOQLGrammar wired up.
 * The adapter is mocked because prepareBindings() only needs getQueryGrammar().
 */
function makeConnection(): SOQLConnection
{
    $adapter = Mockery::mock(SalesforceAdapter::class);
    $connection = new SOQLConnection($adapter);
    $grammar = new SOQLGrammar($connection);
    $connection->setGrammar($grammar);

    return $connection;
}

/**
 * Return a minimal Account describe response including the named fields.
 */
function bindingsDescribe(array $extraFields = []): array
{
    $base = [
        ['name' => 'Id'],
        ['name' => 'Name'],
        ['name' => 'IsActive'],
        ['name' => 'Phone'],
        ['name' => 'NumberOfEmployees'],
        ['name' => 'CreatedDate'],
        ['name' => 'LastModifiedDate'],
        ['name' => 'IsDeleted'],
    ];

    return ['fields' => array_merge($base, $extraFields)];
}

// ---------------------------------------------------------------------------
// Setup / teardown
// ---------------------------------------------------------------------------

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);
});

afterEach(function () {
    Mockery::close();
});

// ===========================================================================
// Unit: prepareBindings() in isolation
// ===========================================================================

describe('SOQLConnection::prepareBindings() — unit', function () {

    it('returns an empty array unchanged', function () {
        $connection = makeConnection();

        expect($connection->prepareBindings([]))->toBe([]);
    });

    it('passes null values through untouched', function () {
        $connection = makeConnection();

        $result = $connection->prepareBindings([null]);

        expect($result[0])->toBeNull();
    });

    it('converts boolean true to the SOQL literal TRUE', function () {
        $connection = makeConnection();

        $result = $connection->prepareBindings([true]);

        expect($result[0])->toBe('TRUE');
    });

    it('converts boolean false to the SOQL literal FALSE', function () {
        $connection = makeConnection();

        $result = $connection->prepareBindings([false]);

        expect($result[0])->toBe('FALSE');
    });

    it('escapes a single quote in a string binding', function () {
        $connection = makeConnection();

        $result = $connection->prepareBindings(["O'Brien"]);

        expect($result[0])->toBe("O\\'Brien");
    });

    it('escapes multiple single quotes in a single string', function () {
        $connection = makeConnection();

        $result = $connection->prepareBindings(["it's O'Brien's account"]);

        expect($result[0])->toBe("it\\'s O\\'Brien\\'s account");
    });

    it('escapes single quotes in a SOQL injection attempt', function () {
        $connection = makeConnection();

        $injection = "'; DELETE FROM Account --";
        $result = $connection->prepareBindings([$injection]);

        // prepareBindings() escapes the quote with a backslash: ' → \'
        // This prevents SOQL from treating it as a string terminator.
        // The backslash-quote sequence appears at the start of the result.
        expect($result[0])->toBe("\\'; DELETE FROM Account --");
        expect($result[0])->toStartWith("\\'");
    });

    it('leaves integer bindings unchanged', function () {
        $connection = makeConnection();

        $result = $connection->prepareBindings([100]);

        expect($result[0])->toBe(100);
    });

    it('leaves float bindings unchanged', function () {
        $connection = makeConnection();

        $result = $connection->prepareBindings([1234567.89]);

        expect($result[0])->toBe(1234567.89);
    });

    it('leaves plain strings without single quotes unchanged', function () {
        $connection = makeConnection();

        $result = $connection->prepareBindings(['Technology']);

        expect($result[0])->toBe('Technology');
    });

    it('formats a DateTimeInterface binding using the SOQL date format', function () {
        $connection = makeConnection();

        $dt = new DateTime('2024-01-15T10:30:00', new DateTimeZone('UTC'));
        $result = $connection->prepareBindings([$dt]);

        // SOQLGrammar::getDateFormat() returns 'Y-m-d\TH:i:s\Z'
        expect($result[0])->toBe('2024-01-15T10:30:00Z');
    });

    it('formats a DateTimeImmutable binding using the SOQL date format', function () {
        $connection = makeConnection();

        $dt = new DateTimeImmutable('2024-06-01T00:00:00', new DateTimeZone('UTC'));
        $result = $connection->prepareBindings([$dt]);

        expect($result[0])->toBe('2024-06-01T00:00:00Z');
    });

    it('handles a mixed array of binding types in one call', function () {
        $connection = makeConnection();

        $dt = new DateTime('2024-03-01T12:00:00', new DateTimeZone('UTC'));

        $result = $connection->prepareBindings([
            "O'Brien",    // string with quote
            true,          // boolean true
            false,         // boolean false
            null,          // null
            42,            // integer
            $dt,           // DateTime
        ]);

        expect($result[0])->toBe("O\\'Brien");
        expect($result[1])->toBe('TRUE');
        expect($result[2])->toBe('FALSE');
        expect($result[3])->toBeNull();
        expect($result[4])->toBe(42);
        expect($result[5])->toBe('2024-03-01T12:00:00Z');
    });

    it('preserves associative array keys', function () {
        $connection = makeConnection();

        $result = $connection->prepareBindings(['name' => "O'Brien", 'active' => true]);

        expect($result)->toHaveKey('name', "O\\'Brien");
        expect($result)->toHaveKey('active', 'TRUE');
    });

    it('does not mutate null entries at any position in the array', function () {
        $connection = makeConnection();

        $result = $connection->prepareBindings([null, "safe", null]);

        expect($result[0])->toBeNull();
        expect($result[1])->toBe('safe');
        expect($result[2])->toBeNull();
    });
});

// ===========================================================================
// Integration: full execution pipeline
// Verifies that prepareBindings() bindings flow correctly all the way through
// SOQLConnection::select() → run() → prepare() → executeQuery() so that the
// final SOQL string sent to Salesforce is correctly interpolated.
// ===========================================================================

describe('prepareBindings() — full execution pipeline via Forrest::query', function () {

    it('sends an escaped single quote to Salesforce in the executed SOQL', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(fn ($q) => str_contains($q, "O\\'Brien")))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Account::where('Name', "O'Brien")->get();
    });

    it('sends TRUE literal to Salesforce for a boolean true binding', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(fn ($q) => str_contains($q, '= TRUE')))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Account::where('IsActive', true)->get();
    });

    it('sends FALSE literal to Salesforce for a boolean false binding', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(fn ($q) => str_contains($q, '= FALSE')))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Account::where('IsActive', false)->get();
    });

    it('sends an integer binding to Salesforce without quotes', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($q) {
                // 100 should be unquoted
                return str_contains($q, '100') && ! str_contains($q, "'100'");
            }))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Account::where('NumberOfEmployees', 100)->get();
    });

    it('sends a formatted DateTime binding to Salesforce', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(fn ($q) => str_contains($q, '2024-01-01T00:00:00Z')))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Account::where('CreatedDate', '>', new DateTime('2024-01-01', new DateTimeZone('UTC')))->get();
    });

    it('neutralises a SOQL injection attempt in the executed query', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        $injection = "'; DELETE FROM Account --";

        // prepareBindings() converts ' → \' (backslash-quote), so the injected quote
        // is no longer a bare string terminator in the final SOQL.
        // The backslash-quote sequence (\') must be present in the sent query.
        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(fn ($q) => str_contains($q, "\\'")))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Account::where('Name', $injection)->get();
    });

    it('sends a query with mixed binding types correctly interpolated', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        Forrest::shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($q) {
                return str_contains($q, "O\\'Brien")  // escaped string
                    && str_contains($q, '= TRUE')       // boolean
                    && str_contains($q, '50');           // integer
            }))
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Account::where('Name', "O'Brien")
            ->where('IsActive', true)
            ->where('NumberOfEmployees', 50)
            ->get();
    });
});

// ===========================================================================
// toSql() path: documenting actual query string representation
//
// IMPORTANT: SOQLBuilder::toSql() has its own binding substitution path that
// is separate from prepareBindings(). It calls getBindings() directly and
// runs Str::replace("'", "\'") on each binding value. It does NOT convert
// booleans to TRUE/FALSE literals or DateTimeInterface to formatted strings.
//
// Key differences from prepareBindings():
//   - Boolean true  → 1     (not TRUE)
//   - Boolean false → 0     (not FALSE; may render as empty due to Str::replaceArray behavior)
//   - Single quotes are escaped in the binding value but the grammar wraps
//     the whole value in surrounding quotes as part of compiling the WHERE clause
//
// These tests document toSql() as-is so regressions can be caught if the
// method's behavior changes.
// ===========================================================================

describe('SOQLBuilder::toSql() — binding representation', function () {

    it('represents a string with a single quote escaped in the SQL string', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        $sql = Account::where('Name', "O'Brien")->toSql();

        expect($sql)->toContain("O\\'Brien");
    });

    it('represents whereNull as = null (SOQL null comparison)', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        $sql = Account::whereNull('Phone')->toSql();

        expect($sql)->toContain('= null');
    });

    it('represents an integer binding without surrounding quotes', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        $sql = Account::where('NumberOfEmployees', 100)->toSql();

        expect($sql)->toContain('100');
        expect($sql)->not->toContain("'100'");
    });

    it('represents boolean true as 1 in the SQL string (toSql does not call prepareBindings)', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        // toSql() does not invoke prepareBindings(), so booleans are not converted
        // to TRUE/FALSE literals. The grammar stores true as 1 in the binding array.
        // Use get() (which calls prepareBindings()) if you need TRUE/FALSE in SOQL.
        $sql = Account::where('IsActive', true)->toSql();

        expect($sql)->toContain('= 1');
        expect($sql)->not->toContain('TRUE');
    });

    it('escapes a single quote in a SOQL injection attempt in the SQL string', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        // toSql() escapes the quote in the binding value (O\'Brien style),
        // so the backslash-quote sequence is present in the rendered SQL.
        $sql = Account::where('Name', "'; DELETE FROM Account --")->toSql();

        expect($sql)->toContain("\\'");
    });

    it('represents a mixed-binding query with escaped string, integer, and a boolean', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);
        Forrest::shouldReceive('describe')->with('Account')->andReturn(bindingsDescribe());

        $sql = Account::where('Name', "O'Brien")
            ->where('IsActive', true)
            ->where('NumberOfEmployees', 25)
            ->toSql();

        expect($sql)->toContain("O\\'Brien");
        // toSql() uses getBindings() directly; boolean true renders as 1 here
        expect($sql)->toContain('= 1');
        expect($sql)->toContain('25');
    });
});
