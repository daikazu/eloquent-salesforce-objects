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

1. Create a Salesforce External Client App (Salesforce's recommended replacement for Connected Apps) with OAuth enabled
2. Publish the Forrest config:
   ```bash
   php artisan vendor:publish --provider="Omniphx\Forrest\Providers\Laravel\ForrestServiceProvider"
   ```
3. Add your credentials to `.env` and configure `config/forrest.php`

### Choose an OAuth Authentication Flow

Set the flow with `forrest.authentication` (env `SF_AUTH_METHOD`). Every flow except `UserPasswordSoap` authenticates through Salesforce's OAuth token endpoint.

| Flow | Use it for |
|---|---|
| `ClientCredentials` | **Recommended** for server-to-server integrations. Runs as the app's configured "Run As" user. |
| `OAuthJWT` | Server-to-server with a certificate instead of a client secret. |
| `WebServer` | When users authorize the app interactively in a browser. You define the login and callback routes. |
| `UserPassword` | OAuth username-password grant. Works, but Salesforce recommends the flows above for new integrations. |
| `UserPasswordSoap` | **Don't use.** It calls SOAP API `login()`, which Salesforce is retiring (see below). |

```env
SF_AUTH_METHOD=ClientCredentials
SF_CONSUMER_KEY=your_consumer_key
SF_CONSUMER_SECRET=your_consumer_secret
SF_LOGIN_URL=https://your-domain.my.salesforce.com
```

> **SOAP API `login()` is being retired.** Salesforce is retiring SOAP API `login()` in API versions 31.0 through 64.0 with the Summer '27 release. It is disabled by default in orgs created in Summer '26 or later, and from Winter '27 it requires the **Use Any API Auth** user permission (without it, `login()` is rejected with `INSUFFICIENT_ACCESS`). Forrest's `UserPasswordSoap` flow uses `login()`, so this package logs a warning on authentication and `php artisan salesforce:test` reports it when that flow is configured. Switch to `ClientCredentials` or `OAuthJWT`.

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
