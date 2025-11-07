# Custom Apex REST Endpoints

Learn how to interact with custom Apex REST endpoints in your Salesforce org.

## Table of Contents

- [Overview](#overview)
- [Basic Usage](#basic-usage)
- [HTTP Methods](#http-methods)
- [Request Body](#request-body)
- [Query Parameters](#query-parameters)
- [Response Handling](#response-handling)
- [Path Normalization](#path-normalization)
- [Error Handling](#error-handling)
- [Real-World Examples](#real-world-examples)
- [Best Practices](#best-practices)

## Overview

The `apexRest()` method provides a convenient way to call custom Apex REST endpoints in your Salesforce org. This method uses Forrest's `custom()` method under the hood, which automatically handles the `/services/apexrest` prefix for you.

This is useful when you need to execute custom business logic or complex operations that go beyond standard CRUD operations.

### When to Use Custom Apex REST

- Complex business logic requiring multiple operations
- Custom validation or processing rules
- Batch processing or data transformations
- Integration with external systems
- Custom reporting or analytics

## Basic Usage

Access the adapter and call your custom endpoint:

```php
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

$adapter = app(SalesforceAdapter::class);

// Simple GET request
$response = $adapter->apexRest('/GetOrderStatus', [
    'method' => 'GET',
]);

// POST request with body
$response = $adapter->apexRest('/CreateOrder', [
    'method' => 'POST',
    'body'   => [
        'customerId' => 'CUST001',
        'orderTotal' => 299.99,
    ],
]);
```

## HTTP Methods

The `apexRest()` method supports all standard HTTP methods:

### GET

Retrieve data from your endpoint:

```php
$response = $adapter->apexRest('/GetCustomerData', [
    'method' => 'GET',
]);

// Method defaults to GET if not specified
$response = $adapter->apexRest('/GetCustomerData');
```

### POST

Create new records or execute operations:

```php
$response = $adapter->apexRest('/CreateOrder', [
    'method' => 'POST',
    'body'   => [
        'customerId' => 'CUST001',
        'items' => [
            ['productId' => 'PROD1', 'quantity' => 2],
            ['productId' => 'PROD2', 'quantity' => 1],
        ],
    ],
]);
```

### PATCH

Update existing records or modify state:

```php
$response = $adapter->apexRest('/UpdateOrderStatus', [
    'method' => 'PATCH',
    'body'   => [
        'orderId' => 'ORD-12345',
        'status'  => 'shipped',
    ],
]);
```

### DELETE

Remove records or cancel operations:

```php
$response = $adapter->apexRest('/CancelOrder', [
    'method' => 'DELETE',
]);
```

### PUT

Replace entire resources:

```php
$response = $adapter->apexRest('/ReplaceConfiguration', [
    'method' => 'PUT',
    'body'   => [
        'settings' => [
            'enabled' => true,
            'threshold' => 100,
        ],
    ],
]);
```

## Request Body

### Simple Data

Send simple key-value pairs:

```php
$response = $adapter->apexRest('/UpdateUser', [
    'method' => 'PATCH',
    'body'   => [
        'firstName' => 'John',
        'lastName'  => 'Doe',
        'email'     => 'john@example.com',
    ],
]);
```

### Nested Arrays

Send complex nested data structures:

```php
$response = $adapter->apexRest('/ProcessOrder', [
    'method' => 'POST',
    'body'   => [
        'customer' => [
            'id'    => 'CUST001',
            'name'  => 'Acme Corp',
            'email' => 'orders@acme.com',
        ],
        'items' => [
            [
                'productId' => 'PROD1',
                'quantity'  => 5,
                'price'     => 49.99,
            ],
            [
                'productId' => 'PROD2',
                'quantity'  => 3,
                'price'     => 29.99,
            ],
        ],
        'shippingAddress' => [
            'street'  => '123 Main St',
            'city'    => 'Springfield',
            'state'   => 'IL',
            'zipCode' => '62701',
        ],
    ],
]);
```

### Field Mapping

The adapter automatically applies field mapping (if configured) to request bodies, converting between Laravel naming conventions (snake_case) and Salesforce conventions (PascalCase):

```php
// These fields will be mapped according to your configuration
$response = $adapter->apexRest('/CreateRecord', [
    'method' => 'POST',
    'body'   => [
        'customer_name' => 'John Doe',  // May be mapped to CustomerName
        'order_total'   => 150.00,      // May be mapped to OrderTotal
    ],
]);
```

## Query Parameters

You can pass query string parameters to your endpoint using the `parameters` option:

### Basic Parameters

```php
$response = $adapter->apexRest('/SearchOrders', [
    'method'     => 'GET',
    'parameters' => [
        'status' => 'active',
        'limit'  => 10,
    ],
]);

// This will call: /services/apexrest/SearchOrders?status=active&limit=10
```

### Complex Search

```php
$response = $adapter->apexRest('/FindProducts', [
    'method'     => 'GET',
    'parameters' => [
        'category'  => 'Electronics',
        'minPrice'  => 50,
        'maxPrice'  => 500,
        'inStock'   => true,
        'sortBy'    => 'price',
        'sortOrder' => 'asc',
    ],
]);
```

### With POST Body

You can combine query parameters with a request body:

```php
$response = $adapter->apexRest('/ProcessOrder', [
    'method'     => 'POST',
    'body'       => [
        'items' => [
            ['productId' => 'PROD1', 'qty' => 2],
        ],
    ],
    'parameters' => [
        'validateOnly' => true,
        'sendEmail'    => false,
    ],
]);
```

## Response Handling

### Array Responses

Most responses will be returned as arrays:

```php
$response = $adapter->apexRest('/GetOrder');

// Access response data
echo $response['orderId'];
echo $response['status'];
echo $response['total'];
```

### Complex Responses

Handle nested response structures:

```php
$response = $adapter->apexRest('/GetOrderDetails');

// Access nested data
foreach ($response['items'] as $item) {
    echo "Product: {$item['name']}, Qty: {$item['quantity']}";
}

echo "Customer: {$response['customer']['name']}";
```

### Non-Array Responses

If your Apex endpoint returns a simple string or other non-array value, it will be wrapped in an array:

```php
// If endpoint returns just a string
$response = $adapter->apexRest('/GetSimpleStatus');

// Response will be: ['response' => 'the string value']
echo $response['response'];
```

## Path Normalization

The `apexRest()` method automatically normalizes endpoint paths for convenience using Forrest's `custom()` method, which handles the `/services/apexrest` prefix.

### Flexible Path Formats

All of these work identically:

```php
// Simple path
$adapter->apexRest('CreateOrder', [...]);

// With leading slash (recommended - matches Forrest documentation)
$adapter->apexRest('/CreateOrder', [...]);

// With trailing slash
$adapter->apexRest('CreateOrder/', [...]);

// With both
$adapter->apexRest('/CreateOrder/', [...]);

// All of the above result in the same API call to:
// {instanceUrl}/services/apexrest/CreateOrder
```

### How It Works

The adapter:
1. Normalizes your path to ensure it starts with `/` (e.g., `CreateOrder` becomes `/CreateOrder`)
2. Passes it to Forrest's `custom()` method
3. Forrest automatically prepends `/services/apexrest` and your instance URL

```php
// You write this
$adapter->apexRest('/MyEndpoint');

// The adapter calls
Forrest::custom('/MyEndpoint', $options);

// Forrest makes the actual API call to
// {instanceUrl}/services/apexrest/MyEndpoint
```

**Note:** You should NOT include `/services/apexrest` in your path - it's added automatically by Forrest.

## Error Handling

### Catching Exceptions

Handle Salesforce API errors:

```php
use Daikazu\EloquentSalesforceObjects\Exceptions\SalesforceException;

try {
    $response = $adapter->apexRest('/CreateOrder', [
        'method' => 'POST',
        'body'   => $orderData,
    ]);

    // Process successful response
    $orderId = $response['orderId'];

} catch (SalesforceException $e) {
    // Handle Salesforce-specific errors
    Log::error('Salesforce API error: ' . $e->getMessage());

    // Get original exception if available
    $originalError = $e->getPrevious();
}
```

### Invalid Method Validation

The method validates HTTP methods before making the request:

```php
try {
    $adapter->apexRest('/Endpoint', [
        'method' => 'INVALID', // This will throw an exception
    ]);
} catch (SalesforceException $e) {
    // Message: "Invalid HTTP method: INVALID. Allowed methods: GET, POST, PATCH, DELETE, PUT"
}
```

## Real-World Examples

### E-commerce Order Creation

```php
public function createOrder(array $orderData)
{
    $adapter = app(SalesforceAdapter::class);

    try {
        $response = $adapter->apexRest('/CreateOrder', [
            'method' => 'POST',
            'body'   => [
                'customerId' => $orderData['customer_id'],
                'orderDate'  => now()->toDateString(),
                'items'      => collect($orderData['items'])->map(function ($item) {
                    return [
                        'productId' => $item['product_id'],
                        'quantity'  => $item['quantity'],
                        'unitPrice' => $item['price'],
                    ];
                })->toArray(),
                'shipping' => [
                    'method'  => $orderData['shipping_method'],
                    'address' => $orderData['shipping_address'],
                ],
                'payment' => [
                    'method' => $orderData['payment_method'],
                    'amount' => $orderData['total'],
                ],
            ],
        ]);

        return [
            'success'   => true,
            'orderId'   => $response['orderId'],
            'orderNumber' => $response['orderNumber'],
            'total'     => $response['total'],
        ];

    } catch (SalesforceException $e) {
        Log::error('Failed to create order in Salesforce', [
            'error' => $e->getMessage(),
            'data'  => $orderData,
        ]);

        return [
            'success' => false,
            'error'   => $e->getMessage(),
        ];
    }
}
```

### Batch Data Processing

```php
public function processBatchUpdate(array $records)
{
    $adapter = app(SalesforceAdapter::class);

    $response = $adapter->apexRest('/ProcessBatch', [
        'method' => 'POST',
        'body'   => [
            'operation' => 'update',
            'records'   => $records,
            'options'   => [
                'validateOnly' => false,
                'allOrNone'    => true,
            ],
        ],
    ]);

    return [
        'processed'   => $response['processedCount'],
        'succeeded'   => $response['successCount'],
        'failed'      => $response['failureCount'],
        'errors'      => $response['errors'] ?? [],
    ];
}
```

### Status Polling

```php
public function checkProcessingStatus(string $jobId)
{
    $adapter = app(SalesforceAdapter::class);

    $response = $adapter->apexRest('/GetJobStatus', [
        'method' => 'GET',
    ]);

    return [
        'status'      => $response['status'],
        'progress'    => $response['progress'],
        'isComplete'  => $response['status'] === 'Completed',
        'hasErrors'   => $response['errorCount'] > 0,
    ];
}
```

### Custom Validation

```php
public function validateData(array $data)
{
    $adapter = app(SalesforceAdapter::class);

    $response = $adapter->apexRest('/ValidateCustomData', [
        'method' => 'POST',
        'body'   => [
            'data' => $data,
            'rules' => [
                'strictMode' => true,
                'checkDuplicates' => true,
            ],
        ],
    ]);

    if (!$response['isValid']) {
        throw ValidationException::withMessages($response['errors']);
    }

    return true;
}
```

### Integration Service

```php
public function syncWithExternalSystem(string $recordId)
{
    $adapter = app(SalesforceAdapter::class);

    $response = $adapter->apexRest('/SyncExternal', [
        'method' => 'POST',
        'body'   => [
            'recordId'     => $recordId,
            'systemName'   => 'ERP',
            'syncType'     => 'full',
            'includeRelated' => true,
        ],
    ]);

    return [
        'syncId'       => $response['syncId'],
        'syncedAt'     => $response['timestamp'],
        'recordsSynced' => $response['recordCount'],
        'externalId'   => $response['externalSystemId'],
    ];
}
```

## Best Practices

### 1. Use Dependency Injection

Inject the adapter in your services:

```php
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

class OrderService
{
    public function __construct(
        protected SalesforceAdapter $adapter
    ) {}

    public function createOrder(array $data)
    {
        return $this->adapter->apexRest('/CreateOrder', [
            'method' => 'POST',
            'body'   => $data,
        ]);
    }
}
```

### 2. Wrap in Try-Catch

Always handle potential exceptions:

```php
try {
    $response = $adapter->apexRest('/Endpoint', $options);
} catch (SalesforceException $e) {
    // Handle error appropriately
}
```

### 3. Log Important Operations

Log API calls for debugging and auditing:

```php
Log::info('Calling Apex REST endpoint', [
    'endpoint' => '/CreateOrder',
    'method'   => 'POST',
]);

$response = $adapter->apexRest('/CreateOrder', $options);

Log::info('Apex REST response received', [
    'endpoint' => '/CreateOrder',
    'success'  => $response['success'] ?? false,
]);
```

### 4. Validate Input Before Calling

Validate data before sending to Salesforce:

```php
$validator = Validator::make($data, [
    'customerId' => 'required|string',
    'items'      => 'required|array|min:1',
]);

if ($validator->fails()) {
    throw new ValidationException($validator);
}

$response = $adapter->apexRest('/CreateOrder', [
    'method' => 'POST',
    'body'   => $data,
]);
```

### 5. Use Meaningful Endpoint Names

Keep endpoint names descriptive and RESTful:

```php
// Good
$adapter->apexRest('/CreateOrder');
$adapter->apexRest('/GetOrderStatus');
$adapter->apexRest('/UpdateCustomer');

// Avoid
$adapter->apexRest('/doStuff');
$adapter->apexRest('/process');
```

### 6. Handle Partial Failures

For batch operations, handle partial successes:

```php
$response = $adapter->apexRest('/ProcessBatch', [
    'method' => 'POST',
    'body'   => ['records' => $records],
]);

$succeeded = collect($response['results'])
    ->filter(fn($result) => $result['success'])
    ->count();

$failed = collect($response['results'])
    ->filter(fn($result) => !$result['success'])
    ->count();

if ($failed > 0) {
    Log::warning("Batch processing had failures", [
        'succeeded' => $succeeded,
        'failed'    => $failed,
    ]);
}
```

### 7. Cache When Appropriate

Cache responses that don't change frequently:

```php
$status = Cache::remember('order_status_' . $orderId, 300, function () use ($adapter, $orderId) {
    return $adapter->apexRest('/GetOrderStatus');
});
```

### 8. Document Your Endpoints

Document custom endpoints in your codebase:

```php
/**
 * Create a new order in Salesforce
 *
 * Endpoint: /CreateOrder
 * Method: POST
 *
 * @param array $orderData Order data including customer, items, shipping
 * @return array Response with orderId, orderNumber, total
 * @throws SalesforceException If order creation fails
 */
public function createOrder(array $orderData): array
{
    return $this->adapter->apexRest('/CreateOrder', [
        'method' => 'POST',
        'body'   => $orderData,
    ]);
}
```

## Next Steps

- Learn about [Bulk Operations](bulk-operations.md) for processing large datasets
- Explore [Query Caching](caching.md) to optimize API usage
- Check out [Error Handling](troubleshooting.md) for debugging tips
