# Querying Data

Master the SOQL query builder to retrieve data from Salesforce.

## Table of Contents

- [Basic Queries](#basic-queries)
- [Where Clauses](#where-clauses)
- [Ordering Results](#ordering-results)
- [Limiting Results](#limiting-results)
- [Selecting Columns](#selecting-columns)
- [Finding Records](#finding-records)
- [Aggregate Functions](#aggregate-functions)
- [Query Caching](#query-caching)
- [Raw SOQL Queries](#raw-soql-queries)
- [Query Debugging](#query-debugging)

## Basic Queries

### Get All Records

```php
use App\Models\Account;

// Get all accounts
$accounts = Account::all();

// Get all with specific columns
$accounts = Account::all(['Id', 'Name', 'Industry']);
```

### Get First Record

```php
// Get first account
$account = Account::first();

// Get first matching specific condition
$account = Account::where('Industry', 'Technology')->first();

// Throw exception if not found
$account = Account::firstOrFail();
```

## Where Clauses

### Basic Where

```php
// Simple equality
$accounts = Account::where('Industry', 'Technology')->get();

// With operator
$accounts = Account::where('AnnualRevenue', '>', 1000000)->get();

// Multiple where clauses (AND)
$accounts = Account::where('Industry', 'Technology')
    ->where('AnnualRevenue', '>', 1000000)
    ->get();
```

### Supported Operators

```php
// Comparison operators
->where('Amount', '>', 1000)      // Greater than
->where('Amount', '>=', 1000)     // Greater than or equal
->where('Amount', '<', 1000)      // Less than
->where('Amount', '<=', 1000)     // Less than or equal
->where('Amount', '!=', 1000)     // Not equal
->where('Amount', '<>', 1000)     // Not equal (alternative)

// LIKE operator
->where('Name', 'LIKE', 'Acme%')          // Starts with
->where('Name', 'LIKE', '%Corp%')         // Contains
->where('Email', 'LIKE', '%@example.com') // Ends with
```

### Where In / Not In

```php
// WHERE IN
$accounts = Account::whereIn('Industry', ['Technology', 'Finance', 'Healthcare'])
    ->get();

// WHERE NOT IN
$accounts = Account::whereNotIn('Industry', ['Government', 'Education'])
    ->get();
```

### Where Null / Not Null

```php
// WHERE field IS NULL
$contacts = Contact::whereNull('Phone')->get();

// WHERE field IS NOT NULL
$contacts = Contact::whereNotNull('Email')->get();
```

### Where Between

```php
// WHERE field BETWEEN min AND max
$opportunities = Opportunity::whereBetween('Amount', [10000, 50000])->get();

// Dates
$accounts = Account::whereBetween('CreatedDate', [
    '2024-01-01',
    '2024-12-31'
])->get();
```

### Date Queries

```php
// WHERE CreatedDate >= DATE
$accounts = Account::whereDate('CreatedDate', '>=', '2024-01-01')->get();

// WHERE CreatedDate = SPECIFIC DATE
$accounts = Account::whereDate('CreatedDate', '2024-01-15')->get();

// Using Carbon
$accounts = Account::whereDate('CreatedDate', '>=', now()->subDays(30))->get();
```

##Ordering Results

### Order By

```php
// Order by single column ascending
$accounts = Account::orderBy('Name')->get();

// Order by single column descending
$accounts = Account::orderBy('CreatedDate', 'desc')->get();

// Order by multiple columns
$accounts = Account::orderBy('Industry')
    ->orderBy('Name')
    ->get();
```

### Latest / Oldest

```php
// Order by CreatedDate desc
$accounts = Account::latest()->get();

// Order by CreatedDate asc
$accounts = Account::oldest()->get();

// Order by specific column
$accounts = Account::latest('LastModifiedDate')->get();
```

## Limiting Results

### Limit and Offset

```php
// LIMIT 10
$accounts = Account::limit(10)->get();

// LIMIT 10 OFFSET 20
$accounts = Account::limit(10)->offset(20)->get();

// Aliases
$accounts = Account::take(10)->skip(20)->get();
```

**Note:** Salesforce has a maximum OFFSET of 2000. For larger datasets, use pagination.

## Selecting Columns

### Select Specific Columns

```php
// Select specific columns
$accounts = Account::select(['Id', 'Name', 'Industry'])->get();

// Select with query builder
$accounts = Account::select(['Id', 'Name'])
    ->where('Industry', 'Technology')
    ->get();
```

### Select All Columns

```php
// Override default columns
$accounts = Account::allColumns()->get();

// Equivalent to
$accounts = Account::select('*')->get();
```

## Finding Records

### Find by ID

```php
// Find single record by ID
$account = Account::find('001xx000003DGb2AAG');

if ($account) {
    echo $account->Name;
}

// Find or throw exception
try {
    $account = Account::findOrFail('001xx000003DGb2AAG');
} catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
    // Handle not found
}
```

### Find Multiple by IDs

```php
// Find multiple records
$ids = ['001xx000003DGb2AAG', '001xx000003DGb2AAH'];
$accounts = Account::find($ids);

foreach ($accounts as $account) {
    echo $account->Name . "\n";
}
```

## Aggregate Functions

### Count

```php
// Count all records
$count = Account::count();

// Count with conditions
$count = Account::where('Industry', 'Technology')->count();

// Count specific column
$count = Account::count('Id');
```

### Sum, Average, Min, Max

```php
// Sum
$totalRevenue = Account::sum('AnnualRevenue');
$totalRevenue = Account::where('Industry', 'Technology')
    ->sum('AnnualRevenue');

// Average
$avgRevenue = Account::avg('AnnualRevenue');
$avgRevenue = Account::average('AnnualRevenue'); // Alias

// Minimum
$minRevenue = Account::min('AnnualRevenue');

// Maximum
$maxRevenue = Account::max('AnnualRevenue');
```

### Exists / Doesn't Exist

```php
// Check if any records exist
if (Account::where('Industry', 'Technology')->exists()) {
    echo "We have technology accounts";
}

// Check if no records exist
if (Account::where('Industry', 'Agriculture')->doesntExist()) {
    echo "No agriculture accounts";
}
```

## Query Caching

### Automatic Caching

Queries are automatically cached:

```php
// First call - hits Salesforce API and caches
$accounts = Account::where('Industry', 'Technology')->get();

// Second identical call - returns from cache
$accounts = Account::where('Industry', 'Technology')->get();
```

### Cache Control

```php
// Skip cache for this query
$accounts = Account::withoutCache()
    ->where('Industry', 'Technology')
    ->get();

// Force refresh cache
$accounts = Account::refreshCache()
    ->where('Industry', 'Technology')
    ->get();

// Custom cache TTL (10 minutes)
$accounts = Account::cacheFor(600)
    ->where('Industry', 'Technology')
    ->get();

// Custom cache tags
$accounts = Account::cacheTags(['important', 'reports'])
    ->where('Industry', 'Technology')
    ->get();
```

See [Query Caching](caching.md) for detailed caching documentation.

## Raw SOQL Queries

### Using Raw Queries

For complex queries, use raw SOQL:

```php
use Daikazu\EloquentSalesforceObjects\Support\SalesforceAdapter;

$adapter = app(SalesforceAdapter::class);

// Raw SOQL query
$result = $adapter->query('
    SELECT Id, Name, (SELECT FirstName, LastName FROM Contacts)
    FROM Account
    WHERE Industry = \'Technology\'
    LIMIT 10
');

// Access results
foreach ($result['records'] as $record) {
    echo $record['Name'] . "\n";

    if (isset($record['Contacts'])) {
        foreach ($record['Contacts']['records'] as $contact) {
            echo "  - {$contact['FirstName']} {$contact['LastName']}\n";
        }
    }
}
```

### When to Use Raw Queries

Use raw SOQL when you need:
- Subqueries (child relationships)
- Complex GROUP BY clauses
- SOQL-specific functions (CALENDAR_YEAR, FORMAT, etc.)
- Relationship queries not yet supported by the builder

## Query Debugging

### View Generated SOQL

```php
// Get the SOQL query string
$soql = Account::where('Industry', 'Technology')
    ->orderBy('Name')
    ->toSql();

echo $soql;
// Output: SELECT Id, Name, Industry FROM Account WHERE Industry = 'Technology' ORDER BY Name ASC
```

### Enable Query Logging

Enable query logging in `config/eloquent-salesforce-objects.php`:

```php
'enable_query_log' => true,
```

Or set in `.env`:

```env
SALESFORCE_QUERY_LOG=true
```

Queries will be logged to your Laravel log file.

## Advanced Query Patterns

### Conditional Queries

```php
$query = Account::query();

if ($request->has('industry')) {
    $query->where('Industry', $request->input('industry'));
}

if ($request->has('min_revenue')) {
    $query->where('AnnualRevenue', '>=', $request->input('min_revenue'));
}

$accounts = $query->get();
```

### Query Scopes

Define reusable query logic in your model:

```php
// In Account model
public function scopeActive($query)
{
    return $query->where('IsActive__c', true);
}

public function scopeTechnology($query)
{
    return $query->where('Industry', 'Technology');
}

public function scopeHighRevenue($query, $threshold = 1000000)
{
    return $query->where('AnnualRevenue', '>=', $threshold);
}

// Usage
$accounts = Account::active()->technology()->get();
$accounts = Account::highRevenue(5000000)->get();
```

### Chunking Results

Process large result sets in chunks:

```php
// Process 200 records at a time
Account::where('Industry', 'Technology')
    ->chunk(200, function ($accounts) {
        foreach ($accounts as $account) {
            // Process account
            $this->processAccount($account);
        }
    });
```

## Query Examples

### Search

```php
// Search accounts by name
$searchTerm = $request->input('search');
$accounts = Account::where('Name', 'LIKE', "%{$searchTerm}%")
    ->limit(50)
    ->get();
```

### Recent Records

```php
// Get accounts created in last 30 days
$recentAccounts = Account::whereDate('CreatedDate', '>=', now()->subDays(30))
    ->orderBy('CreatedDate', 'desc')
    ->get();
```

### Top Records by Value

```php
// Top 10 accounts by revenue
$topAccounts = Account::orderBy('AnnualRevenue', 'desc')
    ->limit(10)
    ->get();
```

### Filter by Multiple Criteria

```php
// Complex filtering
$accounts = Account::where('Industry', 'Technology')
    ->where('AnnualRevenue', '>', 1000000)
    ->whereNotNull('Website')
    ->whereIn('BillingCountry', ['USA', 'Canada'])
    ->orderBy('Name')
    ->get();
```

### Date Range Queries

```php
// Records in date range
$opportunities = Opportunity::whereBetween('CloseDate', [
        now()->startOfMonth(),
        now()->endOfMonth()
    ])
    ->where('StageName', 'Closed Won')
    ->sum('Amount');
```

## Performance Tips

### 1. Select Only Needed Columns

```php
// Slow - gets all fields
$accounts = Account::all();

// Fast - gets only needed fields
$accounts = Account::select(['Id', 'Name', 'Industry'])->get();
```

### 2. Use Caching for Repeated Queries

Queries are cached by default, but you can optimize:

```php
// Cache for longer if data doesn't change often
$accounts = Account::cacheFor(7200) // 2 hours
    ->where('Type', 'Partner')
    ->get();
```

### 3. Limit Results

Always use `limit()` when you don't need all records:

```php
// Get latest 100 accounts
$accounts = Account::latest()->limit(100)->get();
```

### 4. Use Aggregate Functions

Instead of loading all records:

```php
// Bad
$accounts = Account::where('Industry', 'Technology')->get();
$count = $accounts->count(); // Loads all records

// Good
$count = Account::where('Industry', 'Technology')->count(); // Just counts
```

## SOQL Limitations

Be aware of Salesforce SOQL limitations:

- **OFFSET Limit**: Maximum offset of 2000 records
- **Query Timeout**: Queries timeout after 120 seconds
- **Record Limits**: Max 2000 records per query (use pagination for more)
- **No Joins**: SOQL doesn't support traditional SQL joins (use relationships instead)

## Next Steps

- **[CRUD Operations](crud.md)** - Creating, updating, and deleting records
- **[Relationships](relationships.md)** - Querying related objects
- **[Pagination](pagination.md)** - Handling large result sets
- **[Aggregates](aggregates.md)** - Deep dive into aggregate functions
