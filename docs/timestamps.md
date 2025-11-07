# Working with Timestamps

Learn how Salesforce timestamps are automatically cast to Carbon instances in your models.

## Table of Contents

- [Overview](#overview)
- [Automatic Carbon Casting](#automatic-carbon-casting)
- [Available Timestamp Fields](#available-timestamp-fields)
- [Using Carbon Methods](#using-carbon-methods)
- [Timezone Handling](#timezone-handling)
- [Examples](#examples)
- [Common Use Cases](#common-use-cases)

## Overview

All Salesforce models automatically cast `CreatedDate` and `LastModifiedDate` fields to Carbon instances. This allows you to use all of Carbon's powerful date manipulation and formatting methods directly on your Salesforce records.

Carbon is Laravel's date/time library built on top of PHP's DateTime class, providing an expressive API for working with dates and times.

## Automatic Carbon Casting

The `SalesforceModel` base class automatically configures the following fields as datetime casts:

```php
protected function casts(): array
{
    return [
        'CreatedDate'      => 'datetime',
        'LastModifiedDate' => 'datetime',
    ];
}
```

This means whenever you access these fields on a model, you'll receive a Carbon instance instead of a string.

## Available Timestamp Fields

### Standard Salesforce Timestamp Fields

Most Salesforce objects include these standard timestamp fields:

- **`CreatedDate`** - When the record was created
- **`LastModifiedDate`** - When the record was last updated
- **`SystemModstamp`** - System-level modification timestamp

### Custom Date Fields

You can add datetime casting to any custom date/datetime fields in your model:

```php
use Daikazu\EloquentSalesforceObjects\Models\SalesforceModel;

class Opportunity extends SalesforceModel
{
    protected $table = 'Opportunity';

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'CloseDate'       => 'datetime',
            'LastActivityDate' => 'datetime',
            'CustomDate__c'   => 'datetime',
        ]);
    }
}
```

## Using Carbon Methods

Once cast to Carbon, you can use any Carbon method on your timestamp fields.

### Human-Readable Dates

```php
$account = Account::find('001...');

// Get human-readable time difference
echo $account->CreatedDate->diffForHumans();
// Output: "3 days ago"

echo $account->LastModifiedDate->diffForHumans();
// Output: "2 hours ago"
```

### Formatting Dates

```php
$account = Account::first();

// Standard format
echo $account->CreatedDate->format('Y-m-d');
// Output: "2024-01-15"

// Custom format
echo $account->CreatedDate->format('F j, Y');
// Output: "January 15, 2024"

// Time included
echo $account->CreatedDate->format('Y-m-d H:i:s');
// Output: "2024-01-15 10:30:00"
```

### Date Comparisons

```php
$account = Account::find('001...');

// Check if modified after creation
if ($account->LastModifiedDate->isAfter($account->CreatedDate)) {
    echo "Record has been updated";
}

// Check if created recently
if ($account->CreatedDate->isToday()) {
    echo "Created today";
}

if ($account->CreatedDate->isYesterday()) {
    echo "Created yesterday";
}

// Calculate time difference
$daysSinceCreation = $account->CreatedDate->diffInDays(now());
echo "Created {$daysSinceCreation} days ago";
```

### Date Manipulation

```php
$account = Account::first();

// Add time
$futureDate = $account->CreatedDate->addDays(30);
$nextWeek = $account->CreatedDate->addWeek();

// Subtract time
$pastDate = $account->CreatedDate->subMonths(2);

// Start/end of period
$startOfDay = $account->CreatedDate->startOfDay();
$endOfMonth = $account->CreatedDate->endOfMonth();

// Chain methods
$result = $account->CreatedDate
    ->addDays(5)
    ->startOfDay()
    ->format('Y-m-d H:i:s');
```

### Checking Relationships Between Dates

```php
$opportunity = Opportunity::find('006...');

// Check if close date is in the future
if ($opportunity->CloseDate->isFuture()) {
    echo "Opportunity is still open";
}

// Check if close date is past
if ($opportunity->CloseDate->isPast()) {
    echo "Opportunity should be closed";
}

// Check if close date is this week
if ($opportunity->CloseDate->isCurrentWeek()) {
    echo "Opportunity closes this week";
}
```

## Timezone Handling

### Default Behavior

Salesforce stores all timestamps in UTC. When you retrieve a record, the timestamps remain in UTC by default:

```php
$account = Account::first();

echo $account->CreatedDate->timezone;
// Output: UTC (+00:00)

echo $account->CreatedDate->format('Y-m-d H:i:s T');
// Output: "2024-01-15 15:00:00 UTC"
```

### Converting Timezones

You can easily convert timestamps to any timezone using Carbon's timezone methods:

```php
$account = Account::first();

// Convert to New York time
$newYorkTime = $account->CreatedDate->setTimezone('America/New_York');
echo $newYorkTime->format('Y-m-d H:i:s T');
// Output: "2024-01-15 10:00:00 EST"

// Or use the tz() shorthand
$tokyoTime = $account->CreatedDate->tz('Asia/Tokyo');
echo $tokyoTime->format('Y-m-d H:i:s T');
// Output: "2024-01-16 00:00:00 JST"

// Convert to app's default timezone
$localTime = $account->CreatedDate->setTimezone(config('app.timezone'));
```

### Displaying in User's Timezone

For user-facing timestamps, you'll typically want to convert to the user's timezone:

```php
public function show(Account $account)
{
    // Assuming you have user's timezone stored
    $userTimezone = auth()->user()->timezone ?? 'America/New_York';

    return view('account.show', [
        'account' => $account,
        'createdAt' => $account->CreatedDate->tz($userTimezone),
        'updatedAt' => $account->LastModifiedDate->tz($userTimezone),
    ]);
}
```

In your Blade template:

```blade
<p>Created: {{ $createdAt->format('F j, Y g:i A') }}</p>
<p>Updated: {{ $updatedAt->diffForHumans() }}</p>
```

## Examples

### Dashboard Activity Feed

```php
public function recentActivity()
{
    $accounts = Account::query()
        ->orderBy('LastModifiedDate', 'desc')
        ->limit(10)
        ->get();

    foreach ($accounts as $account) {
        echo "{$account->Name} was updated {$account->LastModifiedDate->diffForHumans()}\n";
    }
}

// Output:
// Acme Corp was updated 2 minutes ago
// Widget Inc was updated 1 hour ago
// Tech Solutions was updated 3 days ago
```

### Filtering by Date Range

```php
use Carbon\Carbon;

// Get accounts created in the last 30 days
$recentAccounts = Account::query()
    ->where('CreatedDate', '>=', Carbon::now()->subDays(30))
    ->get();

// Get opportunities closing this month
$thisMonth = Opportunity::query()
    ->whereBetween('CloseDate', [
        Carbon::now()->startOfMonth(),
        Carbon::now()->endOfMonth(),
    ])
    ->get();
```

### Age of Record

```php
public function getRecordAge(Account $account)
{
    $age = $account->CreatedDate->diff(now());

    return sprintf(
        '%d years, %d months, %d days',
        $age->y,
        $age->m,
        $age->d
    );
}
```

### Time Since Last Update

```php
public function needsReview(Account $account): bool
{
    // Check if account hasn't been updated in 90 days
    return $account->LastModifiedDate->diffInDays(now()) > 90;
}
```

### Business Days Calculation

```php
public function getBusinessDaysSinceCreation(Account $account): int
{
    return $account->CreatedDate->diffInDaysFiltered(function (Carbon $date) {
        return $date->isWeekday();
    }, now());
}
```

## Common Use Cases

### 1. Display Created/Updated Information

```blade
<!-- In your Blade template -->
<div class="record-meta">
    <small>
        Created {{ $account->CreatedDate->format('F j, Y') }}
        ({{ $account->CreatedDate->diffForHumans() }})
    </small>
    <small>
        Last updated {{ $account->LastModifiedDate->diffForHumans() }}
    </small>
</div>
```

### 2. Sort by Most Recently Updated

```php
// Get most recently updated accounts
$accounts = Account::query()
    ->orderBy('LastModifiedDate', 'desc')
    ->get();

// Or use the latest scope if defined
$latestAccount = Account::latest('LastModifiedDate')->first();
```

### 3. Archive Old Records

```php
public function archiveOldAccounts()
{
    $cutoffDate = Carbon::now()->subYears(5);

    $oldAccounts = Account::query()
        ->where('LastModifiedDate', '<', $cutoffDate)
        ->get();

    foreach ($oldAccounts as $account) {
        // Archive logic here
        Log::info("Account {$account->Name} hasn't been updated in " .
                 $account->LastModifiedDate->diffForHumans());
    }
}
```

### 4. Calculate SLA Compliance

```php
public function checkSLACompliance(Case $case)
{
    $responseTime = $case->FirstResponseDate
        ->diffInHours($case->CreatedDate);

    // Check if responded within 24 hours
    $metSLA = $responseTime <= 24;

    return [
        'met_sla' => $metSLA,
        'response_time_hours' => $responseTime,
        'response_time_human' => Carbon::parse($case->FirstResponseDate)
            ->diffForHumans($case->CreatedDate, true),
    ];
}
```

### 5. Birthday/Anniversary Reminders

```php
public function getUpcomingAnniversaries()
{
    $contacts = Contact::all();

    return $contacts->filter(function ($contact) {
        if (!$contact->Birthdate) {
            return false;
        }

        $birthday = $contact->Birthdate->setYear(now()->year);

        // Check if birthday is in the next 7 days
        return $birthday->isBetween(now(), now()->addWeek());
    });
}
```

### 6. Audit Trail

```php
public function getAuditInfo(SalesforceModel $record): array
{
    return [
        'created' => [
            'date' => $record->CreatedDate->format('Y-m-d H:i:s'),
            'by' => $record->CreatedBy->Name ?? 'Unknown',
            'ago' => $record->CreatedDate->diffForHumans(),
        ],
        'modified' => [
            'date' => $record->LastModifiedDate->format('Y-m-d H:i:s'),
            'by' => $record->LastModifiedBy->Name ?? 'Unknown',
            'ago' => $record->LastModifiedDate->diffForHumans(),
        ],
        'days_since_created' => $record->CreatedDate->diffInDays(now()),
        'days_since_modified' => $record->LastModifiedDate->diffInDays(now()),
    ];
}
```

## Tips and Best Practices

### 1. Always Check for Null

Some custom date fields might be null:

```php
if ($opportunity->CloseDate) {
    echo $opportunity->CloseDate->diffForHumans();
} else {
    echo "No close date set";
}

// Or use optional chaining
echo $opportunity->CloseDate?->diffForHumans() ?? 'No close date';
```

### 2. Use Carbon's Testing Helpers

When writing tests, use Carbon's testing helpers:

```php
use Carbon\Carbon;

// In your test
Carbon::setTestNow('2024-01-15 12:00:00');

$account = Account::create(['Name' => 'Test']);
// CreatedDate will be the test time

Carbon::setTestNow(); // Reset
```

### 3. Cache Formatted Dates

If you're formatting dates multiple times, consider caching:

```php
public function getFormattedCreatedDateAttribute()
{
    return $this->CreatedDate->format('F j, Y');
}

// Usage
echo $account->formatted_created_date;
```

### 4. Use Accessor Methods

Create accessor methods for commonly used date formats:

```php
public function getCreatedAtForHumansAttribute()
{
    return $this->CreatedDate->diffForHumans();
}

// Usage
echo $account->created_at_for_humans;
```

## Next Steps

- Learn about [CRUD Operations](crud.md)
- Explore [Querying Data](querying.md)
- Check out [Relationships](relationships.md)
