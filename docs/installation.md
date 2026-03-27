# Installation

## Requirements

- PHP 8.4+
- Laravel 12.0+
- Salesforce account with API access

## Install

```bash
composer require daikazu/eloquent-salesforce-objects
```

## Configure Salesforce Connection

This package uses [omniphx/forrest](https://github.com/omniphx/forrest) for Salesforce authentication and API communication. Follow the [Forrest documentation](https://github.com/omniphx/forrest#setting-up-connected-app) to:

1. Create a Salesforce Connected App
2. Publish the Forrest config:
   ```bash
   php artisan vendor:publish --provider="Omniphx\Forrest\Providers\Laravel\ForrestServiceProvider"
   ```
3. Add your credentials to `.env` and configure `config/forrest.php`

## Publish Package Config (Optional)

```bash
php artisan vendor:publish --tag="eloquent-salesforce-objects-config"
```

This creates `config/eloquent-salesforce-objects.php`. See the [Configuration Reference](configuration.md) for all available options.

## Test the Connection

Verify everything is working:

```bash
php artisan salesforce:test
```

This authenticates with Salesforce and runs a simple describe call to confirm the connection is working.

## Next Steps

- [Quickstart Guide](quickstart.md) - Build your first Salesforce integration
- [Model Generator](model-generator.md) - Scaffold models from live metadata
- [Configuration Reference](configuration.md) - Fine-tune your setup
