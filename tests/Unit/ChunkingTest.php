<?php

/**
 * Salesforce caps SOQL OFFSET at 2000, so chunk()/each()/lazy() page by Id
 * ("Id > last order by Id") when the query has no order, offset or limit.
 */

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);
    Forrest::shouldReceive('hasToken')->andReturn(true);
});

afterEach(function () {
    Mockery::close();
});

/**
 * Fake Account table of $count rows (Ids 001…01 upward). Honours "Id > '…'",
 * "limit n" and "offset n", and records every query.
 */
function fakeAccountsForChunking(int $count): ArrayObject
{
    $queries = new ArrayObject;
    $rows = array_map(fn ($i) => ['Id' => sprintf('001xx%013d', $i), 'Name' => "A{$i}"], range(1, $count));

    Forrest::shouldReceive('query')->andReturnUsing(function (string $soql) use ($rows, $queries) {
        $queries[] = $soql;

        if (preg_match("/Id > '([^']+)'/", $soql, $after)) {
            $rows = array_values(array_filter($rows, fn ($row) => strcmp($row['Id'], $after[1]) > 0));
        }

        $offset = preg_match('/offset (\d+)/', $soql, $o) ? (int) $o[1] : 0;
        $limit = preg_match('/limit (\d+)/', $soql, $l) ? (int) $l[1] : null;
        $rows = array_slice($rows, $offset, $limit);

        return ['totalSize' => count($rows), 'done' => true, 'records' => $rows];
    });

    return $queries;
}

it('chunk() pages by Id instead of OFFSET', function () {
    $queries = fakeAccountsForChunking(5);
    $seen = [];

    Account::select(['Id', 'Name'])->chunk(2, function ($accounts) use (&$seen) {
        array_push($seen, ...$accounts->pluck('Id')->all());
    });

    expect($seen)->toHaveCount(5);
    expect(implode("\n", $queries->getArrayCopy()))->not->toContain('offset');
    expect($queries[0])->toBe('select Id, Name from Account where Id != null order by Id asc limit 2');
    expect($queries[1])->toBe("select Id, Name from Account where Id > '001xx0000000000002' order by Id asc limit 2");
});

it('chunk() stops when the callback returns false', function () {
    $queries = fakeAccountsForChunking(10);

    Account::select(['Id'])->chunk(2, fn () => false);

    expect($queries)->toHaveCount(1);
});

it('each() goes past what OFFSET could reach', function () {
    $queries = fakeAccountsForChunking(7);
    $count = 0;

    Account::select(['Id'])->each(function () use (&$count) {
        $count++;
    }, 3);

    expect($count)->toBe(7);
    expect(implode("\n", $queries->getArrayCopy()))->not->toContain('offset');
});

it('lazy() pages by Id instead of OFFSET', function () {
    $queries = fakeAccountsForChunking(5);

    expect(Account::select(['Id'])->lazy(2)->count())->toBe(5);
    expect(implode("\n", $queries->getArrayCopy()))->not->toContain('offset');
});

it('keeps OFFSET paging when the query has its own order, so that order is respected', function () {
    $queries = fakeAccountsForChunking(3);

    Account::select(['Id'])->orderBy('Name')->chunk(2, fn () => null);

    expect($queries[1])->toContain('offset 2');
    expect($queries[0])->toContain('order by Name asc');
});
