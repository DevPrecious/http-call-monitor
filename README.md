# HTTP Call Monitor

See latency and failure rate for every third-party API your Laravel app calls.

It records calls made through Laravel's `Http` client into your own database. There's no external service to sign up for and no extra infrastructure to run.

Requires PHP 8.2+ and Laravel 12 or 13.

## Install

The package isn't on Packagist yet, so tell Composer where to find it first:

```bash
composer config repositories.http-call-monitor vcs https://github.com/DevPrecious/http-call-monitor
composer require http-call-monitor/laravel:dev-main
php artisan migrate
```

Recording starts straight away.

## See the results

In the terminal:

```bash
php artisan http-call-monitor:stats --hours=24
```

Or in the browser at `/http-call-monitor`. The dashboard is only open in the `local` environment until you decide who else can see it, usually in `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewHttpCallMonitor', fn ($user) => $user->is_admin);
```

## Reading the numbers

| Column | Meaning |
|---|---|
| Failure rate | Share of calls that got a 5xx response or no response at all |
| 4xx | Client errors. Listed separately because they usually mean the request was wrong, not that the API is down |
| No response | Timeouts, DNS failures, refused connections and TLS errors |
| p50 / p95 | Response time for half / 95% of calls. Calls with no response are left out |

Each retry and each redirect counts as its own call, because each one is a real request to that host.

## What gets stored

Each call stores the host, method, masked path, status code, duration, time, and for calls with no response, the error text.

**Never stored:** request and response bodies, headers, query strings and URL credentials. URLs are also removed from error text, because cURL errors include the full address.

Path segments that look like identifiers or tokens are replaced with `{id}`, so `/v1/customers/12345` is stored as `/v1/customers/{id}`. That covers:

- numeric segments
- segments containing an email address
- segments of 16 or more characters with a digit or mixed case

Masking is a best-effort pattern match. If an API puts sensitive values in its paths in some other form, add a `path_replacements` rule or list its host in `ignore_hosts`.

## Configuration

Publish the config file to change the defaults:

```bash
php artisan vendor:publish --tag=http-call-monitor-config
```

| Option | Default | Purpose |
|---|---|---|
| `enabled` | `true`, except under `APP_ENV=testing` | Turns recording on or off (`HTTP_CALL_MONITOR_ENABLED`) |
| `storage.connection` | your default | Store calls on a separate database connection (`HTTP_CALL_MONITOR_DB_CONNECTION`) |
| `flush_at` | `50` | Calls held in memory before writing |
| `retention_days` | `14` | Age at which calls are deleted. `null` keeps everything |
| `ignore_hosts` | `[]` | Hosts to skip. Wildcards work: `*.internal` |
| `path_replacements` | `[]` | Extra regex masking: `'#/tenants/[^/]+#' => '/tenants/{tenant}'` |
| `dashboard.enabled` | `true` | Turns the web dashboard on or off (`HTTP_CALL_MONITOR_DASHBOARD`) |
| `dashboard.path` | `http-call-monitor` | Dashboard URL (`HTTP_CALL_MONITOR_PATH`) |
| `dashboard.middleware` | `['web']` | Middleware for the dashboard route |

Old calls are removed daily by `http-call-monitor:prune`, which needs [Laravel's scheduler](https://laravel.com/docs/scheduling) to be running. Without it, run the command yourself.

## Good to know

- **It only sees Laravel's `Http` client.** Vendor SDKs that create their own Guzzle or cURL client aren't recorded.
- **It's built not to get in your app's way.** Calls are written after the response is sent, or after each job in a queue worker. If storage fails, your app carries on and one warning is logged.
- **A failed call isn't lost to a rollback.** Writes are held back until the surrounding database transaction has finished.

## Tests

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
