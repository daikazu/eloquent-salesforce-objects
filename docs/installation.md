# Installation

This guide will walk you through installing and configuring the Eloquent Salesforce Objects package.

## Requirements

Before installing, ensure your environment meets these requirements:

- **PHP**: 8.4 or higher
- **Laravel**: 12.0 or higher
- **Salesforce**: Account with API access enabled
- **Composer**: For package management

## Step 1: Install the Package

Install via Composer:

```bash
composer require daikazu/eloquent-salesforce-objects
```

The package will automatically register its service provider.

## Step 2: Publish Configuration Files

Publish the configuration files for both this package and the underlying Forrest library:

```bash
# Publish Eloquent Salesforce Objects config
php artisan vendor:publish --tag="eloquent-salesforce-objects-config"

# Publish Forrest config (Salesforce REST API client)
php artisan vendor:publish --provider="Omniphx\Forrest\Providers\Laravel\ForrestServiceProvider"
```

This creates:
- `config/eloquent-salesforce-objects.php` - Package configuration
- `config/forrest.php` - Salesforce API connection configuration

## Step 3: Configure Salesforce Connection

### 3.1 Create a Salesforce Connected App

1. Log in to Salesforce
2. Go to **Setup** → **Platform Tools** → **Apps** → **App Manager**
3. Click **New Connected App**
4. Fill in:
   - **Connected App Name**: Your Laravel App
   - **API Name**: Your_Laravel_App
   - **Contact Email**: your-email@example.com
5. Under **API (Enable OAuth Settings)**:
   - Check **Enable OAuth Settings**
   - **Callback URL**: `https://your-domain.com/callback` (or `http://localhost:8000/callback` for local)
   - **Selected OAuth Scopes**: Add these scopes:
     - Full access (full)
     - Perform requests on your behalf at any time (refresh_token, offline_access)
     - Access and manage your data (api)
6. Click **Save**
7. Note your **Consumer Key** (Client ID) and **Consumer Secret** (Client Secret)

### 3.2 Configure Environment Variables

Add your Salesforce credentials to `.env`:

```env
# Salesforce OAuth Credentials
CONSUMER_KEY=your_consumer_key_from_salesforce
CONSUMER_SECRET=your_consumer_secret_from_salesforce
CALLBACK_URI=https://your-domain.com/callback
LOGIN_URL=https://login.salesforce.com
USERNAME=your-salesforce-username
PASSWORD=your-salesforce-password

# Salesforce API Configuration
SALESFORCE_API_VERSION=v64.0
SALESFORCE_INSTANCE_URL=https://your-instance.salesforce.com

# Optional: Query Caching
SALESFORCE_QUERY_CACHE=true
SALESFORCE_QUERY_CACHE_TTL=3600
SALESFORCE_CACHE_DRIVER=redis

# Optional: Cache Invalidation Strategy
SALESFORCE_CACHE_INVALIDATION_STRATEGY=record
SALESFORCE_AUTO_INVALIDATE_CACHE=true
```

### 3.3 Configure Forrest Settings

Edit `config/forrest.php`:

```php
return [
    'authentication' => 'UserPassword', // or 'WebServer' for OAuth flow

    'credentials' => [
        'consumerKey' => env('CONSUMER_KEY'),
        'consumerSecret' => env('CONSUMER_SECRET'),
        'callbackURI' => env('CALLBACK_URI'),
        'loginURL' => env('LOGIN_URL'),
    ],

    'username' => env('USERNAME'),
    'password' => env('PASSWORD'),

    'version' => '64.0',
    'instanceURL' => env('SALESFORCE_INSTANCE_URL'),

    'storage' => [
        'type' => 'cache', // Use Laravel cache for tokens
        'path' => 'forrest_',
    ],
];
```

## Step 4: Test the Connection

Create a test route to verify the connection:

```php
// routes/web.php
use Daikazu\EloquentSalesforceObjects\Examples\Account;

Route::get('/test-salesforce', function () {
    try {
        $accounts = Account::limit(5)->get();
        return response()->json([
            'success' => true,
            'count' => $accounts->count(),
            'accounts' => $accounts,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
});
```

Visit `/test-salesforce` in your browser. If successful, you'll see your Salesforce accounts!

## Authentication Methods

### Method 1: User-Password Flow (Recommended for Server-to-Server)

Best for: Background jobs, scheduled tasks, API integrations

```env
# In .env
USERNAME=your-salesforce-username
PASSWORD=your-salesforce-password
```

```php
// In config/forrest.php
'authentication' => 'UserPassword',
```

The package automatically authenticates when needed.

### Method 2: OAuth Web Server Flow

Best for: Web applications where users log in with their own Salesforce credentials

```php
// In config/forrest.php
'authentication' => 'WebServer',
```

Create authentication routes:

```php
// routes/web.php
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;

Route::get('/salesforce/auth', function () {
    return Forrest::authenticate();
});

Route::get('/callback', function () {
    Forrest::callback();
    return redirect('/dashboard')->with('success', 'Connected to Salesforce!');
});
```

Users must visit `/salesforce/auth` to authorize the app.

## Optional Configuration

### Enable Query Caching

For better performance, enable query caching:

```env
SALESFORCE_QUERY_CACHE=true
SALESFORCE_QUERY_CACHE_TTL=3600  # Cache for 1 hour
SALESFORCE_CACHE_DRIVER=redis    # Use Redis (recommended)
```

See [Query Caching](caching.md) for detailed configuration.

### Enable Field Mapping

Convert between Laravel snake_case and Salesforce PascalCase automatically:

```env
SALESFORCE_ENABLE_FIELD_MAPPING=true
SALESFORCE_NAMING_CONVENTION=snake_case
```

See [Configuration Reference](configuration.md) for all options.

## Troubleshooting

### "Authentication failed" Error

1. Verify your Consumer Key and Secret are correct
2. Check your username and password
3. If using IP restrictions, allowlist your server's IP in Salesforce
4. Ensure your Salesforce user has API access enabled

### "API version not supported" Error

Update the API version in your `.env`:

```env
SALESFORCE_API_VERSION=v64.0
```

### "SSL certificate problem" Error

This usually occurs in local development. You can disable SSL verification (NOT for production):

```php
// config/forrest.php
'verify' => env('APP_ENV') === 'production',
```

### Cannot find Salesforce objects

1. Ensure your user has permissions to access the object
2. Check the object API name (Account, not Accounts)
3. Verify your namespace if using custom package objects

For more troubleshooting, see [Troubleshooting Guide](troubleshooting.md).

## Next Steps

Now that you're installed, check out:

- [Quickstart Guide](quickstart.md) - Build your first Salesforce integration
- [Configuration Reference](configuration.md) - Fine-tune your setup
- [Models](models.md) - Create Salesforce models

