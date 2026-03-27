<?php

use Daikazu\EloquentSalesforceObjects\Support\ResponseParser;

describe('normalize', function () {
    it('returns an array unchanged', function () {
        $parser = new ResponseParser;
        $input = ['foo' => 'bar', 'baz' => 1];

        $result = $parser->parseListResponse($input);

        expect($result)->toBe($input);
    });

    it('decodes a valid JSON string into an array', function () {
        $parser = new ResponseParser;
        $json = json_encode(['key' => 'value', 'count' => 42]);

        $result = $parser->parseListResponse($json);

        expect($result)->toBe(['key' => 'value', 'count' => 42]);
    });

    it('returns an empty array for an invalid JSON string', function () {
        $parser = new ResponseParser;

        $result = $parser->parseListResponse('not valid json {{');

        expect($result)->toBe([]);
    });

    it('returns an empty array for a plain string that is not JSON', function () {
        $parser = new ResponseParser;

        $result = $parser->parseListResponse('just a string');

        expect($result)->toBe([]);
    });

    it('returns an empty array for an integer input', function () {
        $parser = new ResponseParser;

        $result = $parser->parseListResponse(99);

        expect($result)->toBe([]);
    });

    it('returns an empty array for a null input', function () {
        $parser = new ResponseParser;

        $result = $parser->parseListResponse(null);

        expect($result)->toBe([]);
    });

    it('returns an empty array for an object input', function () {
        $parser = new ResponseParser;

        $result = $parser->parseListResponse(new stdClass);

        expect($result)->toBe([]);
    });
});

describe('parseListResponse', function () {
    it('passes an array response through unchanged', function () {
        $parser = new ResponseParser;
        $response = ['version' => '58.0', 'url' => '/services/data/v58.0'];

        $result = $parser->parseListResponse($response);

        expect($result)->toBe($response);
    });

    it('decodes and returns a JSON string response', function () {
        $parser = new ResponseParser;
        $response = json_encode(['version' => '58.0', 'url' => '/services/data/v58.0']);

        $result = $parser->parseListResponse($response);

        expect($result)->toBe(['version' => '58.0', 'url' => '/services/data/v58.0']);
    });

    it('returns an empty array for an unsupported input type', function () {
        $parser = new ResponseParser;

        $result = $parser->parseListResponse(false);

        expect($result)->toBe([]);
    });
});

describe('parseRecordResponse', function () {
    it('strips top-level attributes key from a single record', function () {
        $parser = new ResponseParser;
        $response = [
            'attributes' => ['type' => 'Account', 'url' => '/services/data/v58.0/sobjects/Account/001xx'],
            'Id'         => '001xx000001',
            'Name'       => 'Acme Corp',
        ];

        $result = $parser->parseRecordResponse($response);

        expect($result)->toBe(['Id' => '001xx000001', 'Name' => 'Acme Corp']);
        expect($result)->not->toHaveKey('attributes');
    });

    it('returns a record with no attributes key unchanged', function () {
        $parser = new ResponseParser;
        $response = ['Id' => '001xx000001', 'Name' => 'Acme Corp'];

        $result = $parser->parseRecordResponse($response);

        expect($result)->toBe(['Id' => '001xx000001', 'Name' => 'Acme Corp']);
    });

    it('decodes a JSON string before stripping attributes', function () {
        $parser = new ResponseParser;
        $response = json_encode([
            'attributes' => ['type' => 'Contact'],
            'Id'         => '003xx000001',
            'LastName'   => 'Smith',
        ]);

        $result = $parser->parseRecordResponse($response);

        expect($result)->toBe(['Id' => '003xx000001', 'LastName' => 'Smith']);
    });

    it('returns an empty array for an unsupported input type', function () {
        $parser = new ResponseParser;

        $result = $parser->parseRecordResponse(null);

        expect($result)->toBe([]);
    });
});

describe('stripAttributes — nested relationship records', function () {
    it('flattens a sub-query relationship into a plain array of cleaned records', function () {
        $parser = new ResponseParser;
        $response = [
            'attributes' => ['type' => 'Account'],
            'Id'         => '001xx000001',
            'Name'       => 'Acme Corp',
            'Contacts'   => [
                'totalSize' => 2,
                'done'      => true,
                'records'   => [
                    [
                        'attributes' => ['type' => 'Contact'],
                        'Id'         => '003xx000001',
                        'LastName'   => 'Smith',
                    ],
                    [
                        'attributes' => ['type' => 'Contact'],
                        'Id'         => '003xx000002',
                        'LastName'   => 'Jones',
                    ],
                ],
            ],
        ];

        $result = $parser->parseRecordResponse($response);

        expect($result)->not->toHaveKey('attributes');
        expect($result['Contacts'])->toBeArray();
        expect($result['Contacts'])->toHaveCount(2);
        expect($result['Contacts'][0])->toBe(['Id' => '003xx000001', 'LastName' => 'Smith']);
        expect($result['Contacts'][1])->toBe(['Id' => '003xx000002', 'LastName' => 'Jones']);
        expect($result['Contacts'][0])->not->toHaveKey('attributes');
    });

    it('strips attributes recursively from nested relationship records', function () {
        $parser = new ResponseParser;
        $response = [
            'Id'                   => '001xx000001',
            'OpportunityLineItems' => [
                'records' => [
                    [
                        'attributes'  => ['type' => 'OpportunityLineItem'],
                        'Id'          => 'OLI001',
                        'ProductCode' => 'P-100',
                    ],
                ],
            ],
        ];

        $result = $parser->parseRecordResponse($response);

        expect($result['OpportunityLineItems'][0])->not->toHaveKey('attributes');
        expect($result['OpportunityLineItems'][0]['ProductCode'])->toBe('P-100');
    });
});

describe('stripAttributes — nested objects', function () {
    it('recursively strips attributes from a nested object field', function () {
        $parser = new ResponseParser;
        $response = [
            'attributes'     => ['type' => 'Account'],
            'Id'             => '001xx000001',
            'BillingAddress' => [
                'street' => '123 Main St',
                'city'   => 'New York',
                'state'  => 'NY',
            ],
        ];

        $result = $parser->parseRecordResponse($response);

        expect($result)->not->toHaveKey('attributes');
        expect($result['BillingAddress'])->toBe([
            'street' => '123 Main St',
            'city'   => 'New York',
            'state'  => 'NY',
        ]);
    });

    it('strips attributes keys found inside a nested object', function () {
        $parser = new ResponseParser;
        $response = [
            'Id'    => '001xx000001',
            'Owner' => [
                'attributes' => ['type' => 'User'],
                'Id'         => '005xx000001',
                'Name'       => 'Jane Doe',
            ],
        ];

        $result = $parser->parseRecordResponse($response);

        expect($result['Owner'])->toBe(['Id' => '005xx000001', 'Name' => 'Jane Doe']);
        expect($result['Owner'])->not->toHaveKey('attributes');
    });

    it('preserves empty array values without recursing', function () {
        $parser = new ResponseParser;
        $response = [
            'Id'         => '001xx000001',
            'EmptyField' => [],
        ];

        $result = $parser->parseRecordResponse($response);

        expect($result['EmptyField'])->toBe([]);
    });
});

describe('parseQueryResponse with JSON string input', function () {
    it('decodes a JSON string and maps records through stripAttributes', function () {
        $parser = new ResponseParser;
        $response = json_encode([
            'totalSize'      => 2,
            'done'           => true,
            'nextRecordsUrl' => null,
            'records'        => [
                [
                    'attributes' => ['type' => 'Account'],
                    'Id'         => '001xx000001',
                    'Name'       => 'Acme Corp',
                ],
                [
                    'attributes' => ['type' => 'Account'],
                    'Id'         => '001xx000002',
                    'Name'       => 'Globex',
                ],
            ],
        ]);

        $result = $parser->parseQueryResponse($response);

        expect($result['totalSize'])->toBe(2);
        expect($result['done'])->toBeTrue();
        expect($result['nextRecordsUrl'])->toBeNull();
        expect($result['records'])->toHaveCount(2);
        expect($result['records'][0])->toBe(['Id' => '001xx000001', 'Name' => 'Acme Corp']);
        expect($result['records'][1])->toBe(['Id' => '001xx000002', 'Name' => 'Globex']);
        expect($result['records'][0])->not->toHaveKey('attributes');
    });

    it('returns empty records and defaults when given an invalid JSON string', function () {
        $parser = new ResponseParser;

        $result = $parser->parseQueryResponse('{invalid}');

        expect($result['records'])->toBe([]);
        expect($result['totalSize'])->toBe(0);
        expect($result['done'])->toBeTrue();
        expect($result['nextRecordsUrl'])->toBeNull();
    });

    it('returns empty records and defaults for a non-array non-string input', function () {
        $parser = new ResponseParser;

        $result = $parser->parseQueryResponse(null);

        expect($result['records'])->toBe([]);
        expect($result['totalSize'])->toBe(0);
        expect($result['done'])->toBeTrue();
        expect($result['nextRecordsUrl'])->toBeNull();
    });

    it('preserves nextRecordsUrl when present in a JSON string response', function () {
        $parser = new ResponseParser;
        $response = json_encode([
            'totalSize'      => 500,
            'done'           => false,
            'nextRecordsUrl' => '/services/data/v58.0/query/01gxx000001-2000',
            'records'        => [],
        ]);

        $result = $parser->parseQueryResponse($response);

        expect($result['done'])->toBeFalse();
        expect($result['nextRecordsUrl'])->toBe('/services/data/v58.0/query/01gxx000001-2000');
        expect($result['totalSize'])->toBe(500);
    });
});
