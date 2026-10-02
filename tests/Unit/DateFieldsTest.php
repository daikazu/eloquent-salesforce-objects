<?php

/**
 * Date and datetime values are formatted by the field's type from describe metadata:
 * SOQL takes them unquoted, as YYYY-MM-DD for date fields and as UTC
 * YYYY-MM-DDThh:mm:ssZ for datetime fields.
 */

use Carbon\Carbon;
use Daikazu\EloquentSalesforceObjects\Examples\Opportunity;
use Illuminate\Support\Facades\Cache;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);
    Forrest::shouldReceive('hasToken')->andReturn(true);
    Cache::flush();
});

afterEach(function () {
    Mockery::close();
});

function describeOpportunityWithTypes(): void
{
    Forrest::shouldReceive('describe')->with('Opportunity')->andReturn(['fields' => [
        ['name' => 'Id', 'type' => 'id'],
        ['name' => 'Name', 'type' => 'string'],
        ['name' => 'Amount', 'type' => 'currency'],
        ['name' => 'CloseDate', 'type' => 'date'],
        ['name' => 'CreatedDate', 'type' => 'datetime'],
    ]]);
}

function opportunitySql(Closure $where): string
{
    return $where(Opportunity::select(['Id']))->toSql();
}

describe('date fields', function () {
    beforeEach(fn () => describeOpportunityWithTypes());

    it('leaves a date string unquoted', function () {
        expect(opportunitySql(fn ($q) => $q->where('CloseDate', '>', '2025-01-01')))
            ->toBe('select Id from Opportunity where CloseDate > 2025-01-01');
    });

    it('formats a Carbon value as a date, in its own timezone', function () {
        expect(opportunitySql(fn ($q) => $q->where('CloseDate', '>=', Carbon::parse('2025-01-15 23:30', 'America/New_York'))))
            ->toBe('select Id from Opportunity where CloseDate >= 2025-01-15');
    });

    it('formats whereBetween values as dates', function () {
        expect(opportunitySql(fn ($q) => $q->whereBetween('CloseDate', [Carbon::parse('2025-01-01'), Carbon::parse('2025-03-31')])))
            ->toBe('select Id from Opportunity where (CloseDate >= 2025-01-01 and CloseDate <= 2025-03-31)');
    });

    it('formats whereIn values as dates', function () {
        expect(opportunitySql(fn ($q) => $q->whereIn('CloseDate', ['2025-01-01', Carbon::parse('2025-01-02')])))
            ->toBe('select Id from Opportunity where CloseDate in (2025-01-01, 2025-01-02)');
    });

    it('sends the same formatting in the executed query', function () {
        Forrest::shouldReceive('query')->once()
            ->with('select Id from Opportunity where (CloseDate >= 2025-01-01 and CloseDate <= 2025-03-31)')
            ->andReturn(['totalSize' => 0, 'done' => true, 'records' => []]);

        Opportunity::select(['Id'])->whereBetween('CloseDate', [Carbon::parse('2025-01-01'), '2025-03-31'])->get();
    });
});

describe('datetime fields', function () {
    beforeEach(fn () => describeOpportunityWithTypes());

    it('expands a date-only string to midnight UTC', function () {
        expect(opportunitySql(fn ($q) => $q->where('CreatedDate', '>', '2025-01-01')))
            ->toBe('select Id from Opportunity where CreatedDate > 2025-01-01T00:00:00Z');
    });

    it('leaves a full datetime string unquoted', function () {
        expect(opportunitySql(fn ($q) => $q->where('CreatedDate', '>', '2025-01-01T10:30:00.000+0000')))
            ->toBe('select Id from Opportunity where CreatedDate > 2025-01-01T10:30:00.000+0000');
    });

    it('converts Carbon values to UTC', function () {
        expect(opportunitySql(fn ($q) => $q->where('CreatedDate', '>', Carbon::parse('2025-01-01 00:00', 'America/New_York'))))
            ->toBe('select Id from Opportunity where CreatedDate > 2025-01-01T05:00:00Z');
    });
});

describe('values that are not dates', function () {
    beforeEach(fn () => describeOpportunityWithTypes());

    it('quotes and escapes a string on a date field that is not a strict date', function () {
        expect(opportunitySql(fn ($q) => $q->where('CloseDate', '>', "2025-01-01 OR Name != 'x'")))
            ->toBe("select Id from Opportunity where CloseDate > '2025-01-01 OR Name != \\'x\\''");
    });

    it('quotes a date-looking string on a non-date field', function () {
        expect(opportunitySql(fn ($q) => $q->where('Name', '2025-01-01')))
            ->toBe("select Id from Opportunity where Name = '2025-01-01'");
    });

    it('still passes SOQL date literals through', function () {
        expect(opportunitySql(fn ($q) => $q->where('CloseDate', '>', 'LAST_N_DAYS:30')))
            ->toBe('select Id from Opportunity where CloseDate > LAST_N_DAYS:30');
    });
});

describe('field type lookup', function () {
    it('does not describe the object for values that cannot be dates', function () {
        Forrest::shouldReceive('describe')->never();

        expect(opportunitySql(fn ($q) => $q->where('Name', 'Acme')->where('Amount', '>', 5)))
            ->toBe("select Id from Opportunity where Name = 'Acme' and Amount > 5");
    });

    it('falls back to a quoted string when describe fails', function () {
        Forrest::shouldReceive('describe')->andThrow(new RuntimeException('API down'));

        expect(opportunitySql(fn ($q) => $q->where('CloseDate', '>', '2025-01-01')))
            ->toBe("select Id from Opportunity where CloseDate > '2025-01-01'");
    });

    it('describes the object once per query, however many date values it has', function () {
        Forrest::shouldReceive('describe')->with('Opportunity')->once()->andReturn(['fields' => [
            ['name' => 'CloseDate', 'type' => 'date'],
        ]]);
        config(['eloquent-salesforce-objects.metadata_cache_ttl' => 0]);

        expect(opportunitySql(fn ($q) => $q->where('CloseDate', '>', '2025-01-01')->where('CloseDate', '<', '2025-02-01')))
            ->toBe('select Id from Opportunity where CloseDate > 2025-01-01 and CloseDate < 2025-02-01');
    });
});

describe('whereDate() and date-part helpers', function () {
    beforeEach(fn () => describeOpportunityWithTypes());

    it('wraps a datetime field in DAY_ONLY() for whereDate()', function () {
        expect(opportunitySql(fn ($q) => $q->whereDate('CreatedDate', '>=', Carbon::parse('2025-01-15'))))
            ->toBe('select Id from Opportunity where DAY_ONLY(CreatedDate) >= 2025-01-15');
    });

    it('compares a date field directly for whereDate()', function () {
        expect(opportunitySql(fn ($q) => $q->whereDate('CloseDate', '2025-01-15')))
            ->toBe('select Id from Opportunity where CloseDate = 2025-01-15');
    });

    it('quotes a whereDate() value that is not a strict date, instead of inlining it', function () {
        expect(opportunitySql(fn ($q) => $q->whereDate('CloseDate', '>', '2025-01-01 OR Name != null')))
            ->toBe("select Id from Opportunity where CloseDate > '2025-01-01 OR Name != null'");
    });

    it('compiles whereYear / whereMonth / whereDay to SOQL date functions (Laravel zero-pads the month, which SOQL accepts)', function () {
        expect(opportunitySql(fn ($q) => $q->whereYear('CreatedDate', 2025)->whereMonth('CloseDate', '3')->whereDay('CloseDate', '>=', 15)))
            ->toBe('select Id from Opportunity where CALENDAR_YEAR(CreatedDate) = 2025 and CALENDAR_MONTH(CloseDate) = 03 and DAY_IN_MONTH(CloseDate) >= 15');
    });

    it('quotes a non-numeric whereYear() value', function () {
        expect(opportunitySql(fn ($q) => $q->whereYear('CreatedDate', '2025 OR Id != null')))
            ->toBe("select Id from Opportunity where CALENDAR_YEAR(CreatedDate) = '2025 OR Id != null'");
    });
});
