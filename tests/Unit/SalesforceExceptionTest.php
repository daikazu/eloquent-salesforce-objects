<?php

use Daikazu\EloquentSalesforceObjects\Examples\Account;
use Daikazu\EloquentSalesforceObjects\Exceptions\MalformedQueryException;
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Omniphx\Forrest\Exceptions\SalesforceException as ForrestException;
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
 * Build the exception Forrest throws for an HTTP error. Forrest json-decodes the body
 * and re-encodes it as the message, so a non-JSON body becomes the message "null".
 */
function forrestHttpError(int $status, string $body, array $headers = []): ForrestException
{
    $guzzle = new RequestException(
        "Client error: {$status}",
        new Request('GET', 'https://example.my.salesforce.com/services/data/v64.0/query'),
        new Response($status, $headers, $body)
    );

    return new ForrestException(json_encode(json_decode($body, true), JSON_PRETTY_PRINT), $guzzle);
}

describe('SalesforceException messages from HTTP errors', function () {
    it('reports the HTTP status instead of "null" when the body is not JSON', function () {
        Forrest::shouldReceive('query')->andThrow(forrestHttpError(414, ''));

        try {
            app(SalesforceAdapter::class)->query('select Id from Account');
            $this->fail('Expected a SalesforceException');
        } catch (SalesforceException $e) {
            expect($e->getMessage())->toStartWith('Query failed: HTTP 414 Request-URI Too Large');
            expect($e->getMessage())->toContain('too long');
            expect($e->getMessage())->not->toContain('null');
            expect($e->statusCode)->toBe(414);
            expect($e->errorCode)->toBeNull();
            expect($e->getPrevious())->toBeInstanceOf(ForrestException::class);
        }
    });

    it('includes a non-JSON body as plain text', function () {
        Forrest::shouldReceive('query')->andThrow(forrestHttpError(503, '<html><body><h1>Service Unavailable</h1>  Try   later</body></html>'));

        expect(fn () => app(SalesforceAdapter::class)->query('select Id from Account'))
            ->toThrow(SalesforceException::class, 'Query failed: HTTP 503 Service Unavailable: Service Unavailable Try later');
    });

    it('formats Salesforce JSON errors as ERROR_CODE: message', function () {
        Forrest::shouldReceive('query')->andThrow(forrestHttpError(400, json_encode([
            ['message' => 'Request limit exceeded', 'errorCode' => 'REQUEST_LIMIT_EXCEEDED'],
        ])));

        try {
            app(SalesforceAdapter::class)->query('select Id from Account');
            $this->fail('Expected a SalesforceException');
        } catch (SalesforceException $e) {
            expect($e->getMessage())->toBe('Query failed: REQUEST_LIMIT_EXCEEDED: Request limit exceeded (HTTP 400)');
            expect($e->errorCode)->toBe('REQUEST_LIMIT_EXCEEDED');
            expect($e->statusCode)->toBe(400);
        }
    });

    it('joins several Salesforce errors', function () {
        Forrest::shouldReceive('sobjects')->andThrow(forrestHttpError(400, json_encode([
            ['message' => 'Required fields are missing: [Name]', 'errorCode' => 'REQUIRED_FIELD_MISSING'],
            ['message' => 'bad value', 'errorCode' => 'INVALID_FIELD'],
        ])));

        expect(fn () => app(SalesforceAdapter::class)->create('Account', []))
            ->toThrow(SalesforceException::class, 'Create failed for Account: REQUIRED_FIELD_MISSING: Required fields are missing: [Name]; INVALID_FIELD: bad value (HTTP 400)');
    });

    it('throws MalformedQueryException, a SalesforceException, for MALFORMED_QUERY', function () {
        Forrest::shouldReceive('query')->andThrow(forrestHttpError(400, json_encode([
            ['message' => "unexpected token: 'form'", 'errorCode' => 'MALFORMED_QUERY'],
        ])));

        try {
            app(SalesforceAdapter::class)->query('select Id form Account');
            $this->fail('Expected a MalformedQueryException');
        } catch (MalformedQueryException $e) {
            expect($e)->toBeInstanceOf(SalesforceException::class);
            expect($e->getMessage())->toBe("Query failed: MALFORMED_QUERY: unexpected token: 'form' (HTTP 400)");
        }
    });

    it('keeps the original message when there is no HTTP response', function () {
        Forrest::shouldReceive('query')->andThrow(new RuntimeException('Connection refused'));

        expect(fn () => app(SalesforceAdapter::class)->query('select Id from Account'))
            ->toThrow(SalesforceException::class, 'Query failed: Connection refused');
    });
});

describe('Eloquent queries', function () {
    it('throw the SalesforceException itself, not a QueryException wrapping it', function () {
        config(['eloquent-salesforce-objects.throw_exceptions' => true]);
        Forrest::shouldReceive('describe')->andReturn(['fields' => [['name' => 'Id']]]);
        Forrest::shouldReceive('query')->andThrow(forrestHttpError(414, ''));

        try {
            Account::select(['Id'])->get();
            $this->fail('Expected a SalesforceException');
        } catch (Throwable $e) {
            expect($e)->toBeInstanceOf(SalesforceException::class);
            expect($e->getMessage())->toStartWith('Query failed: HTTP 414');
        }
    });

    it('throw it from cursor() too', function () {
        config(['eloquent-salesforce-objects.throw_exceptions' => true]);
        Forrest::shouldReceive('describe')->andReturn(['fields' => [['name' => 'Id']]]);
        Forrest::shouldReceive('query')->andThrow(forrestHttpError(414, ''));

        expect(fn () => iterator_to_array(Account::select(['Id'])->cursor()))
            ->toThrow(SalesforceException::class, 'Query failed: HTTP 414');
    });
});
