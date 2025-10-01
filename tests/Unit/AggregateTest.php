<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
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

describe('count', function () {
    it('returns count from totalSize when records are empty', function () {
        // Mock Forrest authentication
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // Mock COUNT query - Salesforce returns totalSize instead of records
        Forrest::shouldReceive('query')
            ->with('select COUNT() from Account where Test__c = \'TEST\'')
            ->once()
            ->andReturn([
                'totalSize' => 150,
                'done'      => true,
                'records'   => [],
            ]);

        $count = Account::where('Test__c', 'TEST')->count();

        expect($count)->toBe(150);
    });

    it('returns count with wildcard', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select COUNT() from Account')
            ->once()
            ->andReturn([
                'totalSize' => 500,
                'done'      => true,
                'records'   => [],
            ]);

        $count = Account::count();

        expect($count)->toBe(500);
    });

    it('returns count of specific column', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select COUNT(Id) from Account')
            ->once()
            ->andReturn([
                'totalSize' => 250,
                'done'      => true,
                'records'   => [],
            ]);

        $count = Account::count('Id');

        expect($count)->toBe(250);
    });

    it('returns 0 when no records exist', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $count = Account::where('Name', 'NonExistent')->count();

        expect($count)->toBe(0);
    });
});

describe('sum', function () {
    it('returns sum of column values', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        // SUM queries return results in records with expr0
        Forrest::shouldReceive('query')
            ->with('select SUM(AnnualRevenue) from Account')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['expr0' => 5000000],
                ],
            ]);

        $sum = Account::sum('AnnualRevenue');

        expect($sum)->toBe(5000000);
    });

    it('returns 0 when sum is null', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['expr0' => null],
                ],
            ]);

        $sum = Account::where('AnnualRevenue', '>', 0)->sum('AnnualRevenue');

        expect($sum)->toBe(0);
    });

    it('works with where clauses', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select SUM(AnnualRevenue) from Account where Type = \'Customer\'')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['expr0' => 2500000],
                ],
            ]);

        $sum = Account::where('Type', 'Customer')->sum('AnnualRevenue');

        expect($sum)->toBe(2500000);
    });
});

describe('avg and average', function () {
    it('avg returns average of column values', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select AVG(AnnualRevenue) from Account')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['expr0' => 750000],
                ],
            ]);

        $avg = Account::avg('AnnualRevenue');

        expect($avg)->toBe(750000);
    });

    it('average is an alias for avg', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select AVG(NumberOfEmployees) from Account')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['expr0' => 50],
                ],
            ]);

        $average = Account::average('NumberOfEmployees');

        expect($average)->toBe(50);
    });

    it('returns null when no records for avg', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select AVG(AnnualRevenue) from Account where Name = \'NonExistent\'')
            ->once()
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $avg = Account::where('Name', 'NonExistent')->avg('AnnualRevenue');

        expect($avg)->toBeNull();
    });
});

describe('min', function () {
    it('returns minimum value', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select MIN(AnnualRevenue) from Account')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['expr0' => 50000],
                ],
            ]);

        $min = Account::min('AnnualRevenue');

        expect($min)->toBe(50000);
    });

    it('works with date fields', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select MIN(CreatedDate) from Account')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['expr0' => '2020-01-01T00:00:00.000+0000'],
                ],
            ]);

        $min = Account::min('CreatedDate');

        expect($min)->toBe('2020-01-01T00:00:00.000+0000');
    });
});

describe('max', function () {
    it('returns maximum value', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select MAX(AnnualRevenue) from Account')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['expr0' => 10000000],
                ],
            ]);

        $max = Account::max('AnnualRevenue');

        expect($max)->toBe(10000000);
    });

    it('works with where clauses', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select MAX(NumberOfEmployees) from Account where Type = \'Enterprise\'')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['expr0' => 5000],
                ],
            ]);

        $max = Account::where('Type', 'Enterprise')->max('NumberOfEmployees');

        expect($max)->toBe(5000);
    });
});

describe('exists and doesntExist', function () {
    it('exists returns true when records exist', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select Id from Account where Name = \'Acme Corp\' limit 1')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000003DGb2AAG'],
                ],
            ]);

        $exists = Account::where('Name', 'Acme Corp')->exists();

        expect($exists)->toBeTrue();
    });

    it('exists returns false when no records exist', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select Id from Account where Name = \'NonExistent\' limit 1')
            ->once()
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $exists = Account::where('Name', 'NonExistent')->exists();

        expect($exists)->toBeFalse();
    });

    it('doesntExist returns true when no records exist', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select Id from Account where Name = \'NonExistent\' limit 1')
            ->once()
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $doesntExist = Account::where('Name', 'NonExistent')->doesntExist();

        expect($doesntExist)->toBeTrue();
    });

    it('doesntExist returns false when records exist', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select Id from Account where Name = \'Acme Corp\' limit 1')
            ->once()
            ->andReturn([
                'totalSize' => 1,
                'done'      => true,
                'records'   => [
                    ['Id' => '001xx000003DGb2AAG'],
                ],
            ]);

        $doesntExist = Account::where('Name', 'Acme Corp')->doesntExist();

        expect($doesntExist)->toBeFalse();
    });
});

describe('aggregate', function () {
    it('handles custom aggregate functions', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->with('select COUNT(Id) from Account')
            ->once()
            ->andReturn([
                'totalSize' => 100,
                'done'      => true,
                'records'   => [],
            ]);

        $result = Account::aggregate('count', ['Id']);

        expect($result)->toBe(100);
    });

    it('returns null for avg when no results', function () {
        Forrest::shouldReceive('hasToken')->andReturn(true);

        Forrest::shouldReceive('query')
            ->once()
            ->andReturn([
                'totalSize' => 0,
                'done'      => true,
                'records'   => [],
            ]);

        $result = Account::where('Name', 'NonExistent')->aggregate('avg', ['AnnualRevenue']);

        expect($result)->toBeNull();
    });
});
