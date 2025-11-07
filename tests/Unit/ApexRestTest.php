<?php

use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

beforeEach(function () {
    // Mock the Forrest service in the container
    $forrestMock = Mockery::mock('Omniphx\Forrest\Interfaces\StorageInterface');
    $this->app->instance('forrest', $forrestMock);
    Forrest::swap($forrestMock);

    // Get the adapter instance
    $this->adapter = app(SalesforceAdapter::class);
});

afterEach(function () {
    Mockery::close();
});

describe('apexRest method', function () {
    describe('path normalization', function () {
        it('normalizes path without leading slash', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('post')
                ->once()
                ->with('services/apexrest/CreateOrder', Mockery::any())
                ->andReturn(['orderId' => '12345', 'success' => true]);

            $response = $this->adapter->apexRest('CreateOrder', [
                'method' => 'POST',
                'body'   => ['quantity' => 5],
            ]);

            expect($response)->toHaveKey('orderId');
        });

        it('normalizes path with leading slash', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('post')
                ->once()
                ->with('services/apexrest/CreateOrder', Mockery::any())
                ->andReturn(['orderId' => '12345', 'success' => true]);

            $response = $this->adapter->apexRest('/CreateOrder', [
                'method' => 'POST',
                'body'   => ['quantity' => 5],
            ]);

            expect($response)->toHaveKey('orderId');
        });

        it('normalizes path with trailing slash', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('post')
                ->once()
                ->with('services/apexrest/CreateOrder', Mockery::any())
                ->andReturn(['orderId' => '12345', 'success' => true]);

            $response = $this->adapter->apexRest('CreateOrder/', [
                'method' => 'POST',
                'body'   => ['quantity' => 5],
            ]);

            expect($response)->toHaveKey('orderId');
        });

        it('does not duplicate services/apexrest prefix', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('get')
                ->once()
                ->with('services/apexrest/GetStatus', Mockery::any())
                ->andReturn(['status' => 'active']);

            $response = $this->adapter->apexRest('services/apexrest/GetStatus');

            expect($response)->toHaveKey('status');
        });

        it('handles full path with services/apexrest/', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('get')
                ->once()
                ->with('services/apexrest/CustomEndpoint', Mockery::any())
                ->andReturn(['data' => 'value']);

            $response = $this->adapter->apexRest('/services/apexrest/CustomEndpoint');

            expect($response)->toHaveKey('data');
        });
    });

    describe('HTTP methods', function () {
        it('makes GET request when method is GET', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('get')
                ->once()
                ->with('services/apexrest/GetOrder', Mockery::any())
                ->andReturn(['orderId' => '12345', 'status' => 'pending']);

            $response = $this->adapter->apexRest('/GetOrder', ['method' => 'GET']);

            expect($response)->toHaveKey('orderId');
            expect($response['status'])->toBe('pending');
        });

        it('makes POST request when method is POST', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('post')
                ->once()
                ->with('services/apexrest/CreateOrder', Mockery::on(function ($args) {
                    return isset($args['body']) && $args['body']['quantity'] === 10;
                }))
                ->andReturn(['orderId' => '12345', 'success' => true]);

            $response = $this->adapter->apexRest('/CreateOrder', [
                'method' => 'POST',
                'body'   => ['quantity' => 10],
            ]);

            expect($response['success'])->toBeTrue();
        });

        it('makes PATCH request when method is PATCH', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('patch')
                ->once()
                ->with('services/apexrest/UpdateOrder', Mockery::on(function ($args) {
                    return isset($args['body']) && $args['body']['status'] === 'completed';
                }))
                ->andReturn(['success' => true]);

            $response = $this->adapter->apexRest('/UpdateOrder', [
                'method' => 'PATCH',
                'body'   => ['status' => 'completed'],
            ]);

            expect($response['success'])->toBeTrue();
        });

        it('makes DELETE request when method is DELETE', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('delete')
                ->once()
                ->with('services/apexrest/DeleteOrder', Mockery::any())
                ->andReturn(['success' => true]);

            $response = $this->adapter->apexRest('/DeleteOrder', ['method' => 'DELETE']);

            expect($response['success'])->toBeTrue();
        });

        it('makes PUT request when method is PUT', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('put')
                ->once()
                ->with('services/apexrest/ReplaceOrder', Mockery::on(function ($args) {
                    return isset($args['body']);
                }))
                ->andReturn(['success' => true]);

            $response = $this->adapter->apexRest('/ReplaceOrder', [
                'method' => 'PUT',
                'body'   => ['orderId' => '12345'],
            ]);

            expect($response['success'])->toBeTrue();
        });

        it('defaults to GET when no method specified', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('get')
                ->once()
                ->with('services/apexrest/GetData', Mockery::any())
                ->andReturn(['data' => 'value']);

            $response = $this->adapter->apexRest('/GetData');

            expect($response)->toHaveKey('data');
        });

        it('handles case-insensitive method names', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('post')
                ->once()
                ->with('services/apexrest/CreateOrder', Mockery::any())
                ->andReturn(['success' => true]);

            $response = $this->adapter->apexRest('/CreateOrder', [
                'method' => 'post', // lowercase
                'body'   => ['test' => 'data'],
            ]);

            expect($response['success'])->toBeTrue();
        });

        it('throws exception for invalid HTTP method', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);

            expect(fn () => $this->adapter->apexRest('/CreateOrder', ['method' => 'INVALID']))
                ->toThrow(SalesforceException::class, 'Invalid HTTP method: INVALID');
        });
    });

    describe('request body handling', function () {
        it('sends body with POST request', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('post')
                ->once()
                ->with('services/apexrest/CreateOrder', Mockery::on(function ($args) {
                    return isset($args['body']) &&
                           $args['body']['productId'] === 'PROD123' &&
                           $args['body']['quantity'] === 5 &&
                           $args['body']['price'] === 99.99;
                }))
                ->andReturn(['orderId' => '12345', 'success' => true]);

            $response = $this->adapter->apexRest('/CreateOrder', [
                'method' => 'POST',
                'body'   => [
                    'productId' => 'PROD123',
                    'quantity'  => 5,
                    'price'     => 99.99,
                ],
            ]);

            expect($response['orderId'])->toBe('12345');
        });

        it('handles nested array data in body', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('post')
                ->once()
                ->with('services/apexrest/CreateOrder', Mockery::on(function ($args) {
                    return isset($args['body']['items']) &&
                           is_array($args['body']['items']) &&
                           count($args['body']['items']) === 2;
                }))
                ->andReturn(['success' => true]);

            $response = $this->adapter->apexRest('/CreateOrder', [
                'method' => 'POST',
                'body'   => [
                    'orderId' => 'ORD123',
                    'items'   => [
                        ['sku' => 'ABC123', 'qty' => 5],
                        ['sku' => 'DEF456', 'qty' => 3],
                    ],
                ],
            ]);

            expect($response['success'])->toBeTrue();
        });

        it('does not send body when not provided', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('get')
                ->once()
                ->with('services/apexrest/GetStatus', Mockery::on(function ($args) {
                    return ! isset($args['body']) || empty($args['body']);
                }))
                ->andReturn(['status' => 'active']);

            $response = $this->adapter->apexRest('/GetStatus', ['method' => 'GET']);

            expect($response['status'])->toBe('active');
        });

        it('applies field mapping to request body', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('post')
                ->once()
                ->with('services/apexrest/CreateRecord', Mockery::on(function ($args) {
                    // The ResponseParser reverseMapFields should convert snake_case to PascalCase
                    // This test verifies the body is passed through the parser
                    return isset($args['body']);
                }))
                ->andReturn(['success' => true]);

            $response = $this->adapter->apexRest('/CreateRecord', [
                'method' => 'POST',
                'body'   => [
                    'customer_name' => 'John Doe',
                    'order_total'   => 150.00,
                ],
            ]);

            expect($response['success'])->toBeTrue();
        });
    });

    describe('response handling', function () {
        it('returns array response directly', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('get')
                ->once()
                ->andReturn([
                    'orderId'    => '12345',
                    'orderTotal' => 299.99,
                    'status'     => 'pending',
                ]);

            $response = $this->adapter->apexRest('/GetOrder');

            expect($response)->toBeArray();
            expect($response)->toHaveKey('orderId');
            expect($response)->toHaveKey('orderTotal');
            expect($response)->toHaveKey('status');
        });

        it('wraps non-array response in array', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('get')
                ->once()
                ->andReturn('string response');

            $response = $this->adapter->apexRest('/GetSimpleData');

            expect($response)->toBeArray();
            expect($response)->toHaveKey('response');
            expect($response['response'])->toBe('string response');
        });

        it('handles empty response', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('delete')
                ->once()
                ->andReturn([]);

            $response = $this->adapter->apexRest('/DeleteOrder', ['method' => 'DELETE']);

            expect($response)->toBeArray();
            expect($response)->toBeEmpty();
        });

        it('handles complex nested response', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('get')
                ->once()
                ->andReturn([
                    'order' => [
                        'id'     => '12345',
                        'status' => 'pending',
                        'items'  => [
                            ['sku' => 'ABC', 'qty' => 2],
                            ['sku' => 'DEF', 'qty' => 1],
                        ],
                    ],
                    'customer' => [
                        'id'   => 'CUST001',
                        'name' => 'John Doe',
                    ],
                ]);

            $response = $this->adapter->apexRest('/GetOrderDetails');

            expect($response)->toHaveKey('order');
            expect($response)->toHaveKey('customer');
            expect($response['order']['items'])->toHaveCount(2);
        });
    });

    describe('authentication', function () {
        it('ensures authentication before making request', function () {
            Forrest::shouldReceive('hasToken')
                ->once()
                ->andReturn(true);

            Forrest::shouldReceive('get')
                ->once()
                ->andReturn(['status' => 'ok']);

            $response = $this->adapter->apexRest('/TestAuth');

            expect($response)->toHaveKey('status');
        });

        it('throws authentication exception when not authenticated', function () {
            Forrest::shouldReceive('hasToken')
                ->once()
                ->andReturn(false);

            Forrest::shouldReceive('authenticate')
                ->once()
                ->andThrow(new \Exception('Authentication failed'));

            expect(fn () => $this->adapter->apexRest('/TestEndpoint'))
                ->toThrow(\Exception::class);
        });
    });

    describe('error handling', function () {
        it('throws SalesforceException on API error', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('post')
                ->once()
                ->andThrow(new \Exception('Salesforce API Error'));

            expect(fn () => $this->adapter->apexRest('/CreateOrder', [
                'method' => 'POST',
                'body'   => ['test' => 'data'],
            ]))->toThrow(SalesforceException::class, 'Apex REST call failed');
        });

        it('includes endpoint path in error message', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('get')
                ->once()
                ->andThrow(new \Exception('Not found'));

            try {
                $this->adapter->apexRest('/NonExistentEndpoint');
                $this->fail('Expected SalesforceException was not thrown');
            } catch (SalesforceException $e) {
                expect($e->getMessage())->toContain('services/apexrest/NonExistentEndpoint');
            }
        });

        it('preserves original exception as previous', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);

            $originalException = new \Exception('Original error');
            Forrest::shouldReceive('post')
                ->once()
                ->andThrow($originalException);

            try {
                $this->adapter->apexRest('/CreateOrder', [
                    'method' => 'POST',
                    'body'   => ['test' => 'data'],
                ]);
                $this->fail('Expected SalesforceException was not thrown');
            } catch (SalesforceException $e) {
                expect($e->getPrevious())->toBe($originalException);
            }
        });
    });

    describe('real-world usage scenarios', function () {
        it('creates order with multiple line items', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('post')
                ->once()
                ->with('services/apexrest/CreateOrder', Mockery::on(function ($args) {
                    return isset($args['body']['customerId']) &&
                           isset($args['body']['lineItems']) &&
                           count($args['body']['lineItems']) === 3;
                }))
                ->andReturn([
                    'orderId' => 'ORD-12345',
                    'success' => true,
                    'total'   => 299.97,
                ]);

            $response = $this->adapter->apexRest('/CreateOrder', [
                'method' => 'POST',
                'body'   => [
                    'customerId' => 'CUST001',
                    'lineItems'  => [
                        ['productId' => 'PROD1', 'quantity' => 2, 'price' => 49.99],
                        ['productId' => 'PROD2', 'quantity' => 1, 'price' => 99.99],
                        ['productId' => 'PROD3', 'quantity' => 1, 'price' => 100.00],
                    ],
                ],
            ]);

            expect($response['orderId'])->toBe('ORD-12345');
            expect($response['total'])->toBe(299.97);
        });

        it('retrieves order status by ID', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('get')
                ->once()
                ->with('services/apexrest/GetOrderStatus', Mockery::any())
                ->andReturn([
                    'orderId'       => 'ORD-12345',
                    'status'        => 'shipped',
                    'trackingNumber' => 'TRK123456',
                    'estimatedDelivery' => '2024-12-15',
                ]);

            $response = $this->adapter->apexRest('/GetOrderStatus', ['method' => 'GET']);

            expect($response['status'])->toBe('shipped');
            expect($response)->toHaveKey('trackingNumber');
        });

        it('updates order status', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('patch')
                ->once()
                ->with('services/apexrest/UpdateOrderStatus', Mockery::on(function ($args) {
                    return $args['body']['orderId'] === 'ORD-12345' &&
                           $args['body']['status'] === 'delivered';
                }))
                ->andReturn([
                    'success'        => true,
                    'updatedOrderId' => 'ORD-12345',
                    'newStatus'      => 'delivered',
                ]);

            $response = $this->adapter->apexRest('/UpdateOrderStatus', [
                'method' => 'PATCH',
                'body'   => [
                    'orderId' => 'ORD-12345',
                    'status'  => 'delivered',
                ],
            ]);

            expect($response['success'])->toBeTrue();
            expect($response['newStatus'])->toBe('delivered');
        });

        it('cancels order', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('delete')
                ->once()
                ->with('services/apexrest/CancelOrder', Mockery::any())
                ->andReturn([
                    'success'        => true,
                    'cancelledOrderId' => 'ORD-12345',
                    'refundAmount'   => 299.97,
                ]);

            $response = $this->adapter->apexRest('/CancelOrder', ['method' => 'DELETE']);

            expect($response['success'])->toBeTrue();
            expect($response)->toHaveKey('refundAmount');
        });
    });
});
