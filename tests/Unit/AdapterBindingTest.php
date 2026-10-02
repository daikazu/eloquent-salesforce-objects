<?php

use Daikazu\EloquentSalesforceObjects\Contracts\AdapterInterface;
use Daikazu\EloquentSalesforceObjects\Database\SOQLConnection;
use Daikazu\EloquentSalesforceObjects\Database\SOQLGrammar;
use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Illuminate\Database\Query\Builder as QueryBuilder;

afterEach(function () {
    Mockery::close();
});

it('resolves the interface and the concrete adapter to the same singleton', function () {
    expect(app(AdapterInterface::class))->toBe(app(SalesforceAdapter::class));
    expect(app(AdapterInterface::class))->toBe(app(AdapterInterface::class));
});

it('uses a rebound AdapterInterface for reads, not just writes', function () {
    $adapter = Mockery::mock(AdapterInterface::class);
    $adapter->shouldReceive('resolveFields')->andReturnUsing(fn ($object, $columns) => $columns === ['*'] ? ['Id', 'Name'] : $columns);
    $adapter->shouldReceive('queryHistory')->andReturn(collect());
    $adapter->shouldReceive('query')
        ->once()
        ->with("select Id, Name from Account where Name = 'Acme'")
        ->andReturn([
            'totalSize'      => 1,
            'done'           => true,
            'nextRecordsUrl' => null,
            'records'        => [['Id' => '001xx0000000001', 'Name' => 'Acme']],
        ]);

    $this->app->instance(AdapterInterface::class, $adapter);

    $accounts = Account::where('Name', 'Acme')->get(['Id', 'Name']);

    expect($accounts)->toHaveCount(1);
    expect($accounts->first()->Name)->toBe('Acme');
});

it('compiles a where clause on a grammar that has no model', function () {
    $connection = new SOQLConnection(Mockery::mock(AdapterInterface::class));
    $grammar = new SOQLGrammar($connection);
    $connection->setGrammar($grammar);

    $sql = (new QueryBuilder($connection, $grammar))
        ->from('Account')
        ->where('Name', 'Acme')
        ->toSql();

    expect($sql)->toBe("select * from Account where Name = '?'");
});
