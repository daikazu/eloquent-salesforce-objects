# Pagination

Learn how to efficiently paginate large result sets from Salesforce.

## Table of Contents

- [Introduction](#introduction)
- [Basic Pagination](#basic-pagination)
- [Simple Pagination](#simple-pagination)
- [Cursor Pagination](#cursor-pagination)
- [Manual Pagination](#manual-pagination)
- [API Responses](#api-responses)
- [Salesforce Limitations](#salesforce-limitations)
- [Best Practices](#best-practices)

## Introduction

Pagination allows you to break large result sets into manageable pages, improving performance and user experience.

### Why Paginate?

- **Performance**: Loading all records at once is slow
- **Memory**: Large datasets can exhaust memory
- **User Experience**: Users can navigate through results easily
- **API Limits**: Salesforce limits query results to 2000 records

## Basic Pagination

Laravel-style pagination with page numbers and total count.

### Using paginate()

```php
use App\Models\Account;

// Paginate with 20 records per page
$accounts = Account::paginate(20);

// In your view
return view('accounts.index', compact('accounts'));
```

### Blade Template

```blade
<!-- Display accounts -->
@foreach ($accounts as $account)
    <div>{{ $account->Name }}</div>
@endforeach

<!-- Pagination links -->
{{ $accounts->links() }}
```

### Custom Page Size

```php
// 50 records per page
$accounts = Account::paginate(50);

// 10 records per page
$accounts = Account::paginate(10);

// Get page size from request
$perPage = request('per_page', 20); // Default 20
$accounts = Account::paginate($perPage);
```

### Pagination with Conditions

```php
// Paginate filtered results
$accounts = Account::where('Industry', 'Technology')
    ->orderBy('Name')
    ->paginate(20);

// Paginate with relationships
$accounts = Account::with('contacts')
    ->where('AnnualRevenue', '>', 1000000)
    ->paginate(25);
```

### Appending Query Parameters

```php
// Preserve query string in pagination links
$accounts = Account::where('Industry', request('industry'))
    ->paginate(20)
    ->appends(request()->query());

// Manually append parameters
$accounts = Account::paginate(20)->appends([
    'industry' => 'Technology',
    'sort' => 'name',
]);
```

## Simple Pagination

Lighter pagination without total count - just "Next" and "Previous".

### Using simplePaginate()

```php
// Simple pagination (no total count)
$accounts = Account::simplePaginate(20);

// In your view
return view('accounts.index', compact('accounts'));
```

### Benefits of Simple Pagination

- **Faster**: Doesn't count total records
- **Efficient**: Single query instead of two
- **Good for**: Large datasets where count is expensive

### Simple Pagination in Blade

```blade
@foreach ($accounts as $account)
    <div>{{ $account->Name }}</div>
@endforeach

<!-- Simple pagination links (Next/Previous only) -->
{{ $accounts->links() }}
```

## Cursor Pagination

Efficient pagination for very large datasets using cursor-based pagination.

**Note**: Salesforce has specific limitations with OFFSET (max 2000). For datasets larger than 2000 records, use chunking or adjust your query strategy.

## Manual Pagination

Control pagination manually using `limit()` and `offset()`.

### Using limit() and offset()

```php
$perPage = 20;
$page = request('page', 1);
$offset = ($page - 1) * $perPage;

$accounts = Account::limit($perPage)
    ->offset($offset)
    ->get();

// Get total count separately
$total = Account::count();

// Calculate total pages
$totalPages = ceil($total / $perPage);
```

### Building Custom Paginator

```php
use Illuminate\Pagination\LengthAwarePaginator;

$perPage = 20;
$page = request('page', 1);
$offset = ($page - 1) * $perPage;

// Get records
$accounts = Account::limit($perPage)->offset($offset)->get();

// Get total
$total = Account::count();

// Create paginator
$paginator = new LengthAwarePaginator(
    $accounts,
    $total,
    $perPage,
    $page,
    ['path' => request()->url()]
);

return view('accounts.index', ['accounts' => $paginator]);
```

## API Responses

Return paginated data in API responses.

### JSON API Response

```php
public function index()
{
    $accounts = Account::paginate(20);

    return response()->json($accounts);
}

// Response structure:
// {
//     "current_page": 1,
//     "data": [...],
//     "first_page_url": "...",
//     "last_page_url": "...",
//     "next_page_url": "...",
//     "prev_page_url": "...",
//     "per_page": 20,
//     "total": 150,
//     "last_page": 8
// }
```

### Custom API Response

```php
public function index()
{
    $accounts = Account::paginate(20);

    return response()->json([
        'data' => $accounts->items(),
        'pagination' => [
            'total' => $accounts->total(),
            'per_page' => $accounts->perPage(),
            'current_page' => $accounts->currentPage(),
            'last_page' => $accounts->lastPage(),
            'from' => $accounts->firstItem(),
            'to' => $accounts->lastItem(),
        ],
        'links' => [
            'first' => $accounts->url(1),
            'last' => $accounts->url($accounts->lastPage()),
            'prev' => $accounts->previousPageUrl(),
            'next' => $accounts->nextPageUrl(),
        ],
    ]);
}
```

### API Resource with Pagination

```php
use App\Http\Resources\AccountResource;

public function index()
{
    $accounts = Account::paginate(20);

    return AccountResource::collection($accounts);
}
```

## Pagination Examples

### List with Search and Filters

```php
public function index(Request $request)
{
    $query = Account::query();

    // Search
    if ($search = $request->input('search')) {
        $query->where('Name', 'LIKE', "%{$search}%");
    }

    // Filter by industry
    if ($industry = $request->input('industry')) {
        $query->where('Industry', $industry);
    }

    // Filter by revenue range
    if ($minRevenue = $request->input('min_revenue')) {
        $query->where('AnnualRevenue', '>=', $minRevenue);
    }

    // Sort
    $sortBy = $request->input('sort_by', 'Name');
    $sortOrder = $request->input('sort_order', 'asc');
    $query->orderBy($sortBy, $sortOrder);

    // Paginate
    $perPage = $request->input('per_page', 20);
    $accounts = $query->paginate($perPage)->appends($request->query());

    return view('accounts.index', compact('accounts'));
}
```

### Pagination with Eager Loading

```php
$accounts = Account::with(['contacts', 'opportunities'])
    ->where('Type', 'Customer')
    ->paginate(15);

foreach ($accounts as $account) {
    // Relationships already loaded
    echo $account->contacts->count();
}
```

### Paginated Search Results

```php
public function search(Request $request)
{
    $searchTerm = $request->input('q');

    $accounts = Account::where('Name', 'LIKE', "%{$searchTerm}%")
        ->orWhere('Phone', 'LIKE', "%{$searchTerm}%")
        ->orWhere('Website', 'LIKE', "%{$searchTerm}%")
        ->orderBy('Name')
        ->paginate(25)
        ->appends(['q' => $searchTerm]);

    return view('search.results', compact('accounts', 'searchTerm'));
}
```

## Salesforce Limitations

### OFFSET Limit

Salesforce limits OFFSET to 2000 records:

```php
// This works (offset < 2000)
$accounts = Account::limit(100)->offset(1000)->get();

// This fails (offset >= 2000)
$accounts = Account::limit(100)->offset(2500)->get(); // Error!
```

### Workaround for Large Datasets

For datasets larger than 2000 records:

**Option 1: Use chunking**

```php
Account::where('Industry', 'Technology')->chunk(200, function ($accounts) {
    foreach ($accounts as $account) {
        // Process account
    }
});
```

**Option 2: Use date-based pagination**

```php
$lastDate = null;
$perPage = 200;

do {
    $query = Account::orderBy('CreatedDate')->limit($perPage);

    if ($lastDate) {
        $query->where('CreatedDate', '>', $lastDate);
    }

    $accounts = $query->get();

    foreach ($accounts as $account) {
        // Process account
    }

    $lastDate = $accounts->last()?->CreatedDate;
} while ($accounts->count() === $perPage);
```

**Option 3: Use ID-based pagination**

```php
$lastId = null;
$perPage = 200;

do {
    $query = Account::orderBy('Id')->limit($perPage);

    if ($lastId) {
        $query->where('Id', '>', $lastId);
    }

    $accounts = $query->get();

    foreach ($accounts as $account) {
        // Process account
    }

    $lastId = $accounts->last()?->Id;
} while ($accounts->count() === $perPage);
```

## Best Practices

### 1. Use Appropriate Page Sizes

```php
// Good page sizes
paginate(20)  // Lists
paginate(50)  // Data tables
paginate(10)  // Mobile views

// Avoid
paginate(1000) // Too large, slow queries
paginate(5)    // Too small, many page loads
```

### 2. Use simplePaginate for Large Datasets

```php
// Good for large tables
$accounts = Account::simplePaginate(50);

// Avoid paginate() if you don't need total count
```

### 3. Always Add Sorting

```php
// Good - Consistent pagination
$accounts = Account::orderBy('Name')->paginate(20);

// Bad - Inconsistent order between pages
$accounts = Account::paginate(20); // Order may vary
```

### 4. Cache Expensive Pagination Queries

```php
$cacheKey = 'accounts_page_' . request('page', 1);

$accounts = Cache::remember($cacheKey, 600, function () {
    return Account::orderBy('Name')->paginate(20);
});
```

### 5. Limit Max Page Size

```php
// Protect against large page requests
$perPage = min(request('per_page', 20), 100); // Max 100
$accounts = Account::paginate($perPage);
```

### 6. Include Metadata in API Responses

```php
return response()->json([
    'data' => $accounts->items(),
    'meta' => [
        'total' => $accounts->total(),
        'per_page' => $accounts->perPage(),
        'current_page' => $accounts->currentPage(),
        'last_page' => $accounts->lastPage(),
    ]
]);
```

## Complete Example

Here's a complete pagination example with filtering, sorting, and search:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $query = Account::query();

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('Name', 'LIKE', "%{$search}%")
                  ->orWhere('Phone', 'LIKE', "%{$search}%");
            });
        }

        // Filter by industry
        if ($industry = $request->input('industry')) {
            $query->where('Industry', $industry);
        }

        // Filter by type
        if ($type = $request->input('type')) {
            $query->where('Type', $type);
        }

        // Filter by revenue range
        if ($minRevenue = $request->input('min_revenue')) {
            $query->where('AnnualRevenue', '>=', $minRevenue);
        }
        if ($maxRevenue = $request->input('max_revenue')) {
            $query->where('AnnualRevenue', '<=', $maxRevenue);
        }

        // Sorting
        $sortField = $request->input('sort', 'Name');
        $sortDirection = $request->input('direction', 'asc');

        $allowedSorts = ['Name', 'Industry', 'AnnualRevenue', 'CreatedDate'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDirection);
        }

        // Pagination
        $perPage = min($request->input('per_page', 20), 100); // Max 100
        $accounts = $query->paginate($perPage)->appends($request->query());

        // For API
        if ($request->expectsJson()) {
            return response()->json($accounts);
        }

        // For web
        return view('accounts.index', compact('accounts'));
    }
}
```

## Next Steps

- **[Querying](querying.md)** - Learn more query techniques
- **[Performance Tips](configuration.md)** - Optimize your queries
- **[Caching](caching.md)** - Cache paginated results
