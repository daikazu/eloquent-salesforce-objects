<?php

use Daikazu\EloquentSalesforceObjects\Database\SalesforceBatchResult;
use Illuminate\Support\Collection;

describe('get()', function () {
    it('returns the data collection for a successful query', function () {
        $collection = collect([['Id' => '001xx000001', 'Name' => 'Acme']]);

        $result = new SalesforceBatchResult([
            'accounts' => ['success' => true, 'error' => null, 'data' => $collection],
        ]);

        expect($result->get('accounts'))->toBe($collection);
    });

    it('returns null for a failed query', function () {
        $result = new SalesforceBatchResult([
            'accounts' => ['success' => false, 'error' => ['message' => 'QUERY_TIMEOUT'], 'data' => null],
        ]);

        expect($result->get('accounts'))->toBeNull();
    });

    it('returns null for a non-existent query name', function () {
        $result = new SalesforceBatchResult([]);

        expect($result->get('missing'))->toBeNull();
    });
});

describe('failed()', function () {
    it('returns false for a successful query', function () {
        $result = new SalesforceBatchResult([
            'contacts' => ['success' => true, 'error' => null, 'data' => collect()],
        ]);

        expect($result->failed('contacts'))->toBeFalse();
    });

    it('returns true for a failed query', function () {
        $result = new SalesforceBatchResult([
            'contacts' => ['success' => false, 'error' => ['message' => 'MALFORMED_QUERY'], 'data' => null],
        ]);

        expect($result->failed('contacts'))->toBeTrue();
    });

    it('returns true for a non-existent query name', function () {
        $result = new SalesforceBatchResult([]);

        expect($result->failed('missing'))->toBeTrue();
    });
});

describe('successful()', function () {
    it('returns true when the query succeeded', function () {
        $result = new SalesforceBatchResult([
            'leads' => ['success' => true, 'error' => null, 'data' => collect()],
        ]);

        expect($result->successful('leads'))->toBeTrue();
    });

    it('returns false when the query failed', function () {
        $result = new SalesforceBatchResult([
            'leads' => ['success' => false, 'error' => ['message' => 'ERROR'], 'data' => null],
        ]);

        expect($result->successful('leads'))->toBeFalse();
    });

    it('is the inverse of failed()', function () {
        $result = new SalesforceBatchResult([
            'q1' => ['success' => true, 'error' => null, 'data' => collect()],
            'q2' => ['success' => false, 'error' => ['message' => 'ERROR'], 'data' => null],
        ]);

        expect($result->successful('q1'))->toBe(! $result->failed('q1'));
        expect($result->successful('q2'))->toBe(! $result->failed('q2'));
    });
});

describe('error()', function () {
    it('returns the error array for a failed query', function () {
        $error = ['message' => 'QUERY_TIMEOUT', 'errorCode' => 'TIMEOUT'];

        $result = new SalesforceBatchResult([
            'accounts' => ['success' => false, 'error' => $error, 'data' => null],
        ]);

        expect($result->error('accounts'))->toBe($error);
    });

    it('returns null for a successful query', function () {
        $result = new SalesforceBatchResult([
            'accounts' => ['success' => true, 'error' => null, 'data' => collect()],
        ]);

        expect($result->error('accounts'))->toBeNull();
    });

    it('returns null for a non-existent query name', function () {
        $result = new SalesforceBatchResult([]);

        expect($result->error('missing'))->toBeNull();
    });
});

describe('names()', function () {
    it('returns all query names', function () {
        $result = new SalesforceBatchResult([
            'accounts' => ['success' => true, 'error' => null, 'data' => collect()],
            'contacts' => ['success' => true, 'error' => null, 'data' => collect()],
            'leads'    => ['success' => false, 'error' => ['message' => 'ERROR'], 'data' => null],
        ]);

        expect($result->names())->toBe(['accounts', 'contacts', 'leads']);
    });

    it('returns an empty array when there are no results', function () {
        $result = new SalesforceBatchResult([]);

        expect($result->names())->toBe([]);
    });
});

describe('allSuccessful()', function () {
    it('returns true when all queries succeeded', function () {
        $result = new SalesforceBatchResult([
            'accounts' => ['success' => true, 'error' => null, 'data' => collect()],
            'contacts' => ['success' => true, 'error' => null, 'data' => collect()],
        ]);

        expect($result->allSuccessful())->toBeTrue();
    });

    it('returns false when any query failed', function () {
        $result = new SalesforceBatchResult([
            'accounts' => ['success' => true, 'error' => null, 'data' => collect()],
            'contacts' => ['success' => false, 'error' => ['message' => 'ERROR'], 'data' => null],
        ]);

        expect($result->allSuccessful())->toBeFalse();
    });

    it('returns true when there are no results', function () {
        $result = new SalesforceBatchResult([]);

        expect($result->allSuccessful())->toBeTrue();
    });
});

describe('failures()', function () {
    it('returns only the names of failed queries', function () {
        $result = new SalesforceBatchResult([
            'accounts' => ['success' => true, 'error' => null, 'data' => collect()],
            'contacts' => ['success' => false, 'error' => ['message' => 'ERROR'], 'data' => null],
            'leads'    => ['success' => false, 'error' => ['message' => 'TIMEOUT'], 'data' => null],
        ]);

        expect($result->failures())->toBe(['contacts', 'leads']);
    });

    it('returns an empty array when all queries succeeded', function () {
        $result = new SalesforceBatchResult([
            'accounts' => ['success' => true, 'error' => null, 'data' => collect()],
            'contacts' => ['success' => true, 'error' => null, 'data' => collect()],
        ]);

        expect($result->failures())->toBe([]);
    });

    it('returns all names when all queries failed', function () {
        $result = new SalesforceBatchResult([
            'accounts' => ['success' => false, 'error' => ['message' => 'ERROR'], 'data' => null],
            'contacts' => ['success' => false, 'error' => ['message' => 'ERROR'], 'data' => null],
        ]);

        expect($result->failures())->toBe(['accounts', 'contacts']);
    });
});
