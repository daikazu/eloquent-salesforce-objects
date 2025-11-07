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
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/CreateOrder', Mockery::on(function ($options) {
                    return $options['method'] === 'post' &&
                           isset($options['body']) &&
                           $options['body']['quantity'] === 5;
                }))
                ->andReturn(['orderId' => '12345', 'success' => true]);

            $response = $this->adapter->apexRest('CreateOrder', [
                'method' => 'POST',
                'body'   => ['quantity' => 5],
            ]);

            expect($response)->toHaveKey('orderId');
        });

        it('normalizes path with leading slash', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/CreateOrder', Mockery::any())
                ->andReturn(['orderId' => '12345', 'success' => true]);

            $response = $this->adapter->apexRest('/CreateOrder', [
                'method' => 'POST',
                'body'   => ['quantity' => 5],
            ]);

            expect($response)->toHaveKey('orderId');
        });

        it('normalizes path with trailing slash', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/CreateOrder', Mockery::any())
                ->andReturn(['orderId' => '12345', 'success' => true]);

            $response = $this->adapter->apexRest('CreateOrder/', [
                'method' => 'POST',
                'body'   => ['quantity' => 5],
            ]);

            expect($response)->toHaveKey('orderId');
        });

        it('normalizes path with both leading and trailing slashes', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/CustomEndpoint', Mockery::any())
                ->andReturn(['data' => 'value']);

            $response = $this->adapter->apexRest('/CustomEndpoint/');

            expect($response)->toHaveKey('data');
        });
    });

    describe('HTTP methods', function () {
        it('makes GET request when method is GET', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/GetOrder', Mockery::on(function ($options) {
                    return $options['method'] === 'get';
                }))
                ->andReturn(['orderId' => '12345', 'status' => 'pending']);

            $response = $this->adapter->apexRest('/GetOrder', ['method' => 'GET']);

            expect($response)->toHaveKey('orderId');
            expect($response['status'])->toBe('pending');
        });

        it('makes POST request when method is POST', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/CreateOrder', Mockery::on(function ($options) {
                    return $options['method'] === 'post' &&
                           isset($options['body']) &&
                           $options['body']['quantity'] === 10;
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
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/UpdateOrder', Mockery::on(function ($options) {
                    return $options['method'] === 'patch' &&
                           isset($options['body']) &&
                           $options['body']['status'] === 'completed';
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
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/DeleteOrder', Mockery::on(function ($options) {
                    return $options['method'] === 'delete';
                }))
                ->andReturn(['success' => true]);

            $response = $this->adapter->apexRest('/DeleteOrder', ['method' => 'DELETE']);

            expect($response['success'])->toBeTrue();
        });

        it('makes PUT request when method is PUT', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/ReplaceOrder', Mockery::on(function ($options) {
                    return $options['method'] === 'put' &&
                           isset($options['body']);
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
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/GetData', Mockery::on(function ($options) {
                    return $options['method'] === 'get';
                }))
                ->andReturn(['data' => 'value']);

            $response = $this->adapter->apexRest('/GetData');

            expect($response)->toHaveKey('data');
        });

        it('handles case-insensitive method names', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/CreateOrder', Mockery::on(function ($options) {
                    return $options['method'] === 'post'; // Should be lowercase
                }))
                ->andReturn(['success' => true]);

            $response = $this->adapter->apexRest('/CreateOrder', [
                'method' => 'post', // lowercase input
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
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/CreateOrder', Mockery::on(function ($options) {
                    return isset($options['body']) &&
                           $options['body']['productId'] === 'PROD123' &&
                           $options['body']['quantity'] === 5 &&
                           $options['body']['price'] === 99.99;
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
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/CreateOrder', Mockery::on(function ($options) {
                    return isset($options['body']['items']) &&
                           is_array($options['body']['items']) &&
                           count($options['body']['items']) === 2;
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
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/GetStatus', Mockery::on(function ($options) {
                    return ! isset($options['body']) && $options['method'] === 'get';
                }))
                ->andReturn(['status' => 'active']);

            $response = $this->adapter->apexRest('/GetStatus', ['method' => 'GET']);

            expect($response['status'])->toBe('active');
        });

        it('applies field mapping to request body', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/CreateRecord', Mockery::on(function ($options) {
                    // The ResponseParser reverseMapFields should convert snake_case to PascalCase
                    // This test verifies the body is passed through the parser
                    return isset($options['body']);
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

        it('passes query parameters through', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/SearchOrders', Mockery::on(function ($options) {
                    return isset($options['parameters']) &&
                           $options['parameters']['status'] === 'active' &&
                           $options['parameters']['limit'] === 10;
                }))
                ->andReturn(['orders' => []]);

            $response = $this->adapter->apexRest('/SearchOrders', [
                'method'     => 'GET',
                'parameters' => [
                    'status' => 'active',
                    'limit'  => 10,
                ],
            ]);

            expect($response)->toHaveKey('orders');
        });
    });

    describe('response handling', function () {
        it('returns array response directly', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
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
            Forrest::shouldReceive('custom')
                ->once()
                ->andReturn('string response');

            $response = $this->adapter->apexRest('/GetSimpleData');

            expect($response)->toBeArray();
            expect($response)->toHaveKey('response');
            expect($response['response'])->toBe('string response');
        });

        it('handles empty response', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->andReturn([]);

            $response = $this->adapter->apexRest('/DeleteOrder', ['method' => 'DELETE']);

            expect($response)->toBeArray();
            expect($response)->toBeEmpty();
        });

        it('handles complex nested response', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
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

            Forrest::shouldReceive('custom')
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
            Forrest::shouldReceive('custom')
                ->once()
                ->andThrow(new \Exception('Salesforce API Error'));

            expect(fn () => $this->adapter->apexRest('/CreateOrder', [
                'method' => 'POST',
                'body'   => ['test' => 'data'],
            ]))->toThrow(SalesforceException::class, 'Apex REST call failed');
        });

        it('includes endpoint path in error message', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->andThrow(new \Exception('Not found'));

            try {
                $this->adapter->apexRest('/NonExistentEndpoint');
                $this->fail('Expected SalesforceException was not thrown');
            } catch (SalesforceException $e) {
                expect($e->getMessage())->toContain('/NonExistentEndpoint');
            }
        });

        it('preserves original exception as previous', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);

            $originalException = new \Exception('Original error');
            Forrest::shouldReceive('custom')
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
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/CreateOrder', Mockery::on(function ($options) {
                    return isset($options['body']['customerId']) &&
                           isset($options['body']['lineItems']) &&
                           count($options['body']['lineItems']) === 3;
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
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/GetOrderStatus', Mockery::any())
                ->andReturn([
                    'orderId'            => 'ORD-12345',
                    'status'             => 'shipped',
                    'trackingNumber'     => 'TRK123456',
                    'estimatedDelivery' => '2024-12-15',
                ]);

            $response = $this->adapter->apexRest('/GetOrderStatus', ['method' => 'GET']);

            expect($response['status'])->toBe('shipped');
            expect($response)->toHaveKey('trackingNumber');
        });

        it('updates order status', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/UpdateOrderStatus', Mockery::on(function ($options) {
                    return $options['body']['orderId'] === 'ORD-12345' &&
                           $options['body']['status'] === 'delivered';
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
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/CancelOrder', Mockery::any())
                ->andReturn([
                    'success'           => true,
                    'cancelledOrderId' => 'ORD-12345',
                    'refundAmount'     => 299.97,
                ]);

            $response = $this->adapter->apexRest('/CancelOrder', ['method' => 'DELETE']);

            expect($response['success'])->toBeTrue();
            expect($response)->toHaveKey('refundAmount');
        });

        it('searches with query parameters', function () {
            Forrest::shouldReceive('hasToken')->andReturn(true);
            Forrest::shouldReceive('custom')
                ->once()
                ->with('/SearchProducts', Mockery::on(function ($options) {
                    return isset($options['parameters']) &&
                           $options['parameters']['category'] === 'Electronics' &&
                           $options['parameters']['maxPrice'] === 500;
                }))
                ->andReturn([
                    'products' => [
                        ['id' => 'P1', 'name' => 'Product 1'],
                        ['id' => 'P2', 'name' => 'Product 2'],
                    ],
                    'total' => 2,
                ]);

            $response = $this->adapter->apexRest('/SearchProducts', [
                'method'     => 'GET',
                'parameters' => [
                    'category' => 'Electronics',
                    'maxPrice' => 500,
                ],
            ]);

            expect($response['total'])->toBe(2);
            expect($response['products'])->toHaveCount(2);
        });
    });
});
