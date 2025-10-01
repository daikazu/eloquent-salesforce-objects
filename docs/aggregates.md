# Aggregate Functions

Learn how to use aggregate functions (COUNT, SUM, AVG, MIN, MAX) to analyze your Salesforce data.

## Table of Contents

- [Introduction](#introduction)
- [COUNT](#count)
- [SUM](#sum)
- [AVG (Average)](#avg-average)
- [MIN (Minimum)](#min-minimum)
- [MAX (Maximum)](#max-maximum)
- [EXISTS / DOESN'T EXIST](#exists--doesnt-exist)
- [Combining Aggregates](#combining-aggregates)
- [Performance Tips](#performance-tips)

## Introduction

Aggregate functions perform calculations on a set of values and return a single result. They're essential for generating reports, dashboards, and analytics.

### Supported Aggregates

- **COUNT**: Count records
- **SUM**: Sum numeric values
- **AVG**: Calculate average
- **MIN**: Find minimum value
- **MAX**: Find maximum value

## COUNT

Count the number of records matching criteria.

### Basic Count

```php
use App\Models\Account;

// Count all accounts
$totalAccounts = Account::count();

echo "Total accounts: {$totalAccounts}";
```

### Count with Conditions

```php
// Count technology accounts
$techAccounts = Account::where('Industry', 'Technology')->count();

// Count high-revenue accounts
$highValueAccounts = Account::where('AnnualRevenue', '>', 1000000)->count();

// Count with multiple conditions
$activePartners = Account::where('Type', 'Partner')
    ->where('Status__c', 'Active')
    ->count();
```

### Count Specific Column

```php
// Count non-null phone numbers
$accountsWithPhone = Account::count('Phone');

// Count non-null emails
$contactsWithEmail = Contact::count('Email');
```

### Count with Date Filters

```php
// Count accounts created this year
$newAccounts = Account::whereYear('CreatedDate', now()->year)->count();

// Count accounts created in last 30 days
$recentAccounts = Account::whereDate('CreatedDate', '>=', now()->subDays(30))
    ->count();
```

## SUM

Calculate the total of numeric values.

### Basic Sum

```php
// Sum all annual revenue
$totalRevenue = Account::sum('AnnualRevenue');

echo "Total revenue: $" . number_format($totalRevenue);
```

### Sum with Conditions

```php
// Sum revenue for technology companies
$techRevenue = Account::where('Industry', 'Technology')
    ->sum('AnnualRevenue');

// Sum closed-won opportunity amounts
$wonRevenue = Opportunity::where('StageName', 'Closed Won')
    ->sum('Amount');

// Sum this quarter's opportunities
$quarterRevenue = Opportunity::where('StageName', 'Closed Won')
    ->whereBetween('CloseDate', [
        now()->startOfQuarter(),
        now()->endOfQuarter()
    ])
    ->sum('Amount');
```

### Handling Null Values

SUM automatically ignores null values:

```php
// Returns 0 if no records or all values are null
$sum = Account::where('Industry', 'NonExistent')->sum('AnnualRevenue');
// Result: 0
```

## AVG (Average)

Calculate the average of numeric values.

### Basic Average

```php
// Average annual revenue
$avgRevenue = Account::avg('AnnualRevenue');

echo "Average revenue: $" . number_format($avgRevenue);
```

### Average with Conditions

```php
// Average opportunity amount
$avgOppAmount = Opportunity::where('StageName', 'Closed Won')
    ->avg('Amount');

// Average deal size by industry
$avgTechDeal = Opportunity::whereHas('account', function ($query) {
    $query->where('Industry', 'Technology');
})->avg('Amount');

// Average employee count
$avgEmployees = Account::where('Type', 'Customer')
    ->avg('NumberOfEmployees');
```

### Alternative: average()

`average()` is an alias for `avg()`:

```php
$avgRevenue = Account::average('AnnualRevenue');
// Same as: Account::avg('AnnualRevenue')
```

## MIN (Minimum)

Find the minimum value.

### Basic Minimum

```php
// Smallest opportunity amount
$smallestDeal = Opportunity::min('Amount');

// Earliest account created date
$firstAccount = Account::min('CreatedDate');
```

### Minimum with Conditions

```php
// Smallest closed-won deal
$smallestWin = Opportunity::where('StageName', 'Closed Won')
    ->min('Amount');

// Lowest revenue among customers
$lowestRevenue = Account::where('Type', 'Customer')
    ->min('AnnualRevenue');

// Earliest close date for open opportunities
$earliestClose = Opportunity::where('IsClosed', false)
    ->min('CloseDate');
```

### Finding Records with Minimum Value

```php
// Find account with lowest revenue
$lowestRevenueAmount = Account::min('AnnualRevenue');
$lowestRevenueAccount = Account::where('AnnualRevenue', $lowestRevenueAmount)
    ->first();

echo "Lowest revenue account: {$lowestRevenueAccount->Name}";
```

## MAX (Maximum)

Find the maximum value.

### Basic Maximum

```php
// Largest opportunity amount
$largestDeal = Opportunity::max('Amount');

// Most recent account created date
$latestAccount = Account::max('CreatedDate');
```

### Maximum with Conditions

```php
// Largest closed-won deal
$largestWin = Opportunity::where('StageName', 'Closed Won')
    ->max('Amount');

// Highest revenue among technology companies
$highestTechRevenue = Account::where('Industry', 'Technology')
    ->max('AnnualRevenue');

// Latest activity date
$lastActivity = Contact::max('LastActivityDate');
```

### Finding Records with Maximum Value

```php
// Find account with highest revenue
$highestRevenueAmount = Account::max('AnnualRevenue');
$topAccount = Account::where('AnnualRevenue', $highestRevenueAmount)
    ->first();

echo "Top revenue account: {$topAccount->Name} - $" . number_format($topAccount->AnnualRevenue);
```

## EXISTS / DOESN'T EXIST

Check if records exist without retrieving them.

### Exists

```php
// Check if technology accounts exist
if (Account::where('Industry', 'Technology')->exists()) {
    echo "We have technology accounts";
}

// Check if high-value opportunities exist
if (Opportunity::where('Amount', '>', 1000000)->exists()) {
    echo "We have million-dollar opportunities";
}

// Check if any accounts were created today
if (Account::whereDate('CreatedDate', today())->exists()) {
    echo "New accounts created today";
}
```

### Doesn't Exist

```php
// Check if no agriculture accounts exist
if (Account::where('Industry', 'Agriculture')->doesntExist()) {
    echo "No agriculture accounts";
}

// Check if no open cases
if (Case::where('IsClosed', false)->doesntExist()) {
    echo "No open cases - all resolved!";
}
```

## Combining Aggregates

Perform multiple aggregate calculations.

### Multiple Aggregates in One Query

```php
$stats = [
    'count' => Account::count(),
    'total_revenue' => Account::sum('AnnualRevenue'),
    'avg_revenue' => Account::avg('AnnualRevenue'),
    'min_revenue' => Account::min('AnnualRevenue'),
    'max_revenue' => Account::max('AnnualRevenue'),
];

return response()->json($stats);
```

### Aggregates by Category

```php
$industries = ['Technology', 'Manufacturing', 'Healthcare', 'Finance'];
$stats = [];

foreach ($industries as $industry) {
    $stats[$industry] = [
        'count' => Account::where('Industry', $industry)->count(),
        'total_revenue' => Account::where('Industry', $industry)->sum('AnnualRevenue'),
        'avg_revenue' => Account::where('Industry', $industry)->avg('AnnualRevenue'),
    ];
}

return view('reports.industry-stats', compact('stats'));
```

## Practical Examples

### Sales Dashboard

```php
public function salesDashboard()
{
    $stats = [
        // Opportunity metrics
        'total_opportunities' => Opportunity::count(),
        'open_opportunities' => Opportunity::where('IsClosed', false)->count(),
        'won_opportunities' => Opportunity::where('StageName', 'Closed Won')->count(),

        // Revenue metrics
        'total_pipeline' => Opportunity::where('IsClosed', false)->sum('Amount'),
        'won_revenue' => Opportunity::where('StageName', 'Closed Won')->sum('Amount'),
        'avg_deal_size' => Opportunity::avg('Amount'),

        // Account metrics
        'total_accounts' => Account::count(),
        'active_accounts' => Account::where('Status__c', 'Active')->count(),
        'total_account_revenue' => Account::sum('AnnualRevenue'),
    ];

    return view('dashboard.sales', compact('stats'));
}
```

### Monthly Revenue Report

```php
public function monthlyRevenueReport($year)
{
    $months = [];

    for ($month = 1; $month <= 12; $month++) {
        $startDate = "{$year}-{$month}-01";
        $endDate = date('Y-m-t', strtotime($startDate));

        $months[$month] = [
            'month' => date('F', strtotime($startDate)),
            'revenue' => Opportunity::where('StageName', 'Closed Won')
                ->whereBetween('CloseDate', [$startDate, $endDate])
                ->sum('Amount'),
            'deals_closed' => Opportunity::where('StageName', 'Closed Won')
                ->whereBetween('CloseDate', [$startDate, $endDate])
                ->count(),
        ];
    }

    return view('reports.monthly-revenue', compact('months', 'year'));
}
```

### Industry Performance Analysis

```php
public function industryAnalysis()
{
    $industries = Account::select('Industry')
        ->distinct()
        ->whereNotNull('Industry')
        ->pluck('Industry');

    $analysis = [];

    foreach ($industries as $industry) {
        $analysis[] = [
            'industry' => $industry,
            'account_count' => Account::where('Industry', $industry)->count(),
            'total_revenue' => Account::where('Industry', $industry)
                ->sum('AnnualRevenue'),
            'avg_revenue' => Account::where('Industry', $industry)
                ->avg('AnnualRevenue'),
            'max_revenue' => Account::where('Industry', $industry)
                ->max('AnnualRevenue'),
            'opportunity_count' => Opportunity::whereHas('account', function ($query) use ($industry) {
                $query->where('Industry', $industry);
            })->count(),
        ];
    }

    // Sort by total revenue descending
    usort($analysis, function ($a, $b) {
        return $b['total_revenue'] <=> $a['total_revenue'];
    });

    return view('reports.industry-analysis', compact('analysis'));
}
```

### Win Rate Calculator

```php
public function calculateWinRate($startDate, $endDate)
{
    $totalOpps = Opportunity::whereBetween('CreatedDate', [$startDate, $endDate])
        ->count();

    $wonOpps = Opportunity::where('StageName', 'Closed Won')
        ->whereBetween('CreatedDate', [$startDate, $endDate])
        ->count();

    $lostOpps = Opportunity::where('StageName', 'Closed Lost')
        ->whereBetween('CreatedDate', [$startDate, $endDate])
        ->count();

    $winRate = $wonOpps + $lostOpps > 0
        ? ($wonOpps / ($wonOpps + $lostOpps)) * 100
        : 0;

    return [
        'total_opportunities' => $totalOpps,
        'won' => $wonOpps,
        'lost' => $lostOpps,
        'win_rate' => round($winRate, 2) . '%',
        'avg_deal_size' => Opportunity::where('StageName', 'Closed Won')
            ->whereBetween('CreatedDate', [$startDate, $endDate])
            ->avg('Amount'),
    ];
}
```

### Top Performers

```php
public function topPerformers($limit = 10)
{
    // Top accounts by revenue
    $topAccounts = Account::orderBy('AnnualRevenue', 'desc')
        ->limit($limit)
        ->get(['Id', 'Name', 'Industry', 'AnnualRevenue']);

    // Largest opportunities
    $largestDeals = Opportunity::where('StageName', 'Closed Won')
        ->orderBy('Amount', 'desc')
        ->limit($limit)
        ->get(['Id', 'Name', 'Amount', 'CloseDate', 'AccountId']);

    return view('reports.top-performers', compact('topAccounts', 'largestDeals'));
}
```

## Performance Tips

### 1. Use Aggregate Functions Instead of Loading All Records

```php
// Good - Uses COUNT aggregate (fast)
$count = Account::where('Industry', 'Technology')->count();

// Bad - Loads all records then counts (slow)
$accounts = Account::where('Industry', 'Technology')->get();
$count = $accounts->count();
```

### 2. Combine Related Aggregates

```php
// Good - Reuse query builder
$query = Account::where('Industry', 'Technology');
$count = $query->count();
$revenue = $query->sum('AnnualRevenue');

// Avoid - Separate queries for same data
$count = Account::where('Industry', 'Technology')->count();
$revenue = Account::where('Industry', 'Technology')->sum('AnnualRevenue');
```

### 3. Use Caching for Expensive Aggregates

```php
use Illuminate\Support\Facades\Cache;

// Cache dashboard stats for 10 minutes
$stats = Cache::remember('dashboard_stats', 600, function () {
    return [
        'total_revenue' => Account::sum('AnnualRevenue'),
        'total_opportunities' => Opportunity::count(),
        'won_revenue' => Opportunity::where('StageName', 'Closed Won')->sum('Amount'),
    ];
});
```

### 4. Index Fields Used in Aggregates

Ensure Salesforce fields used in WHERE clauses are indexed for better performance.

## Caching Aggregate Queries

**Aggregate queries are NEVER cached.** This ensures you always get current, accurate counts and totals.

```php
// Always hits Salesforce API (never cached)
$totalRevenue = Account::sum('AnnualRevenue');
$accountCount = Account::count();
```

### Why Aggregates Aren't Cached

- Aggregate results need to be current for reports and dashboards
- Users expect accurate counts that reflect the latest data
- Aggregate queries are relatively fast (don't return large datasets)
- Stale aggregate data can be misleading

### Manually Cache Expensive Aggregates

For expensive calculations you want to cache, use explicit caching:

```php
use Illuminate\Support\Facades\Cache;

// Cache for 5 minutes with explicit control
$stats = Cache::remember('dashboard_stats', 300, function () {
    return [
        'total_revenue' => Account::sum('AnnualRevenue'),
        'total_accounts' => Account::count(),
        'avg_deal_size' => Opportunity::avg('Amount'),
    ];
});

// Refresh when needed
Cache::forget('dashboard_stats');
```

See [Query Caching](caching.md#aggregate-query-caching) for more details.

## Next Steps

- **[Querying](querying.md)** - Learn more about query building
- **[Pagination](pagination.md)** - Handle large result sets
- **[Caching](caching.md)** - Optimize performance with caching
