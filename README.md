# csiq/carestack-configuration

PHP gRPC client for CareStack Configuration.

## Usage

Type-hint `IConfigurationClient`. Create the live client once at bootstrap (factory, or a DI container) and inject it. `ConfigurationClientFactory::create()` reads `CONFIG_SERVICE_URI` (default `127.0.0.1:8081`). Reuse one client per process. App classes should not call the factory.

Each public method is one unary RPC. Pick the call that matches how many keys you need. Do not loop `isFeatureEnabled()` for a page of flags.

**One key.** `isFeatureEnabled`, `isDevToggleEnabled`, `tryGet`, `getConfigurationDetails`.

```php
$memoOn = $config->isFeatureEnabled('is_memo_enabled', $practiceId);
$regex = $config->tryGet('dni_regex', $practiceId);
```

**Several known keys, one RPC.** Pass the names to `getAll()`. That hits `GetAllTogglesAndConfigurationsByNames`. Read the `ToggleSet` in memory for the rest of the request.

```php
$set = $config->getAll($practiceId, [
    'is_memo_enabled',
    'is_fax_enabled',
    'is_audio_download_enabled',
    'dni_regex',
], userId: $userId);

$memoOn = $set->toggles['is_memo_enabled'] ?? false;
$regex = $set->configurations['dni_regex'] ?? null;
```

**Every definition for the practice.** `getAll($practiceId)` with an empty name list hits `GetAllTogglesAndConfigurations`. Use this for dumps, admin, or port scripts. It returns flags this app did not create. Prefer the named list on a request path.

Pass `userId` when a user-level value should win over the practice value. Defined toggles with no stored row come back as `false` on `isFeatureEnabled` and in `getAll()` toggle maps. Config names with no value, and unknown names, are omitted from `getAll()`; use `array_key_exists` when "missing" must be distinct from "off".

## Laravel

Require the package from the sibling checkout. The app also needs the `grpc` PHP extension in the same PHP that serves requests. Composer does not install that extension.

```json
"repositories": [
    { "type": "path", "url": "../csiq-config-php" }
]
```

```bash
composer require csiq/carestack-configuration
```

Put the address in `.env` and read it from config, not from `env()` in the provider. After `config:cache`, `getenv()` inside the package does not see `.env`.

```
CONFIG_SERVICE_URI=127.0.0.1:8081
CONFIG_SERVICE_INSECURE=true
CONFIG_SERVICE_TIMEOUT_MS=2000
CONFIG_SERVICE_RETRY_MAX_ATTEMPTS=3
CONFIG_SERVICE_RETRY_INITIAL_BACKOFF_MS=250
CONFIG_SERVICE_RETRY_MAX_BACKOFF_MS=250
CONFIG_SERVICE_RETRY_BACKOFF_MULTIPLIER=1
CONFIG_SERVICE_KEEPALIVE_TIME_MS=60000
CONFIG_SERVICE_KEEPALIVE_TIMEOUT_MS=10000
```

Laravel defaults `timeout_ms` to **2000**. The package itself still defaults to 10000 for CLI (`bin/smoke.php`) and `insecure` to **false**. Local plaintext gRPC needs `CONFIG_SERVICE_INSECURE=true`. Outside `local` and `testing`, boot fails if the URI is loopback (`127.0.0.1`, `localhost`, `::1`) or `insecure` is true.

```php
// config/services.php
'csiq_config' => [
    'uri' => env('CONFIG_SERVICE_URI', '127.0.0.1:8081'),
    'insecure' => filter_var(env('CONFIG_SERVICE_INSECURE', false), FILTER_VALIDATE_BOOLEAN),
    'timeout_ms' => (int) env('CONFIG_SERVICE_TIMEOUT_MS', 2000),
    'retry_max_attempts' => (int) env('CONFIG_SERVICE_RETRY_MAX_ATTEMPTS', 3),
    'retry_initial_backoff_ms' => (int) env('CONFIG_SERVICE_RETRY_INITIAL_BACKOFF_MS', 250),
    'retry_max_backoff_ms' => (int) env('CONFIG_SERVICE_RETRY_MAX_BACKOFF_MS', 250),
    'retry_backoff_multiplier' => (float) env('CONFIG_SERVICE_RETRY_BACKOFF_MULTIPLIER', 1),
    'keepalive_time_ms' => (int) env('CONFIG_SERVICE_KEEPALIVE_TIME_MS', 60000),
    'keepalive_timeout_ms' => (int) env('CONFIG_SERVICE_KEEPALIVE_TIMEOUT_MS', 10000),
],
```

```php
// AppServiceProvider::register()
use Csiq\Configuration\ConfigurationClientConfig;
use Csiq\Configuration\ConfigurationClientFactory;
use Csiq\Configuration\IConfigurationClient;
use Csiq\Configuration\RetryPolicy;
use Illuminate\Support\Facades\Log;
use RuntimeException;

$this->app->singleton(IConfigurationClient::class, function () {
    $settings = config('services.csiq_config');
    $host = strtolower(explode(':', (string) preg_replace('#^[a-z]+://#', '', (string) $settings['uri']), 2)[0]);
    if (
        ! $this->app->environment(['local', 'testing'])
        && ($settings['insecure'] || in_array($host, ['127.0.0.1', 'localhost', '::1'], true))
    ) {
        throw new RuntimeException(
            'CONFIG_SERVICE_URI must be a non-local host with CONFIG_SERVICE_INSECURE=false outside local/testing'
        );
    }

    return ConfigurationClientFactory::create(
        new ConfigurationClientConfig(
            host: $settings['uri'],
            insecure: $settings['insecure'],
            timeoutMs: $settings['timeout_ms'],
            retryPolicy: new RetryPolicy(
                maxAttempts: $settings['retry_max_attempts'],
                initialBackoffMs: $settings['retry_initial_backoff_ms'],
                maxBackoffMs: $settings['retry_max_backoff_ms'],
                backoffMultiplier: $settings['retry_backoff_multiplier'],
            ),
            keepaliveTimeMs: $settings['keepalive_time_ms'],
            keepaliveTimeoutMs: $settings['keepalive_timeout_ms'],
        ),
        Log::getLogger(),
    );
});
```

Pass a PSR-3 logger as the second argument to `ConfigurationClientFactory::create()`. Failed attempts log `warning` (will retry) or `error` (give up), with `attempt`, `code`, and `elapsed_ms`. A first-attempt success is silent. A success after retry logs `warning`.

Inject `IConfigurationClient`. `accountId` is the practice id. Controllers still type-hint the interface. Do not construct `ConfigurationClient` in a controller.

```php
use Csiq\Configuration\IConfigurationClient;
use Csiq\Configuration\ToggleSet;

final class PracticeFeatureFlags
{
    public function __construct(private IConfigurationClient $config)
    {
    }

    public function forPractice(int $practiceId, ?int $userId = null): ToggleSet
    {
        return $this->config->getAll($practiceId, [
            'is_memo_enabled',
            'is_fax_enabled',
            'dni_regex',
        ], userId: $userId);
    }
}
```

Under PHP-FPM the container is rebuilt each request, but the gRPC extension keeps one channel per worker process.

In tests, replace the interface. No network:

```php
$this->app->instance(IConfigurationClient::class, $fake);
```

A missing feature-toggle name and a stored `false` both read as `false` from `isFeatureEnabled()`. `tryGet()` returns `null` only when the name is not a definition, or when a non-toggle config has no stored value.

`culture_id` and other foreign keys stay in the Laravel database. This service holds settings, not relations.

## Environment

| Variable | Default |
|---|---|
| `CONFIG_SERVICE_URI` | `127.0.0.1:8081` |
| `CONFIG_SERVICE_INSECURE` | `false` |
| `CONFIG_SERVICE_TIMEOUT_MS` | `10000` |
| `CONFIG_SERVICE_RETRY_MAX_ATTEMPTS` | `3` |
| `CONFIG_SERVICE_RETRY_INITIAL_BACKOFF_MS` | `250` |
| `CONFIG_SERVICE_RETRY_MAX_BACKOFF_MS` | `250` |
| `CONFIG_SERVICE_RETRY_BACKOFF_MULTIPLIER` | `1` |
| `CONFIG_SERVICE_KEEPALIVE_TIME_MS` | `60000` |
| `CONFIG_SERVICE_KEEPALIVE_TIMEOUT_MS` | `10000` |

Local gRPC is HTTP/2 on **8081**. REST ping stays on **8080**.

### Retries and timeout

Every call (reads and writes) retries on `INTERNAL`, `UNKNOWN` or `UNAVAILABLE`: 3 attempts, 250ms backoff between them. Sleep is equal jitter (half the backoff plus a random remainder, so 125–250ms at the default). With the default multiplier of 1 the cap stays 250ms. Other status codes fail on the first attempt. There is no circuit breaker; a failed call is retried on the next request. Pass a PSR-3 logger into the factory to record attempts and elapsed time.

`CONFIG_SERVICE_TIMEOUT_MS` is a deadline for the whole call, retries and backoff included. Each attempt gets the time that is left, and a retry whose backoff would pass the deadline is not started. A call therefore never blocks the worker longer than the timeout. When it gives up, `ConfigurationException` carries the gRPC status as its code.

### Keepalives

php-grpc cannot dispose a channel the way CareStack recycles `GrpcChannel` every 60s. The client pings instead: `grpc.keepalive_time_ms` (default 60s) and `grpc.keepalive_timeout_ms` (default 10s), with pings allowed on idle channels so FPM workers drop a dead HTTP/2 session without waiting for `pm.max_requests`.

In a framework with cached config (Laravel `config:cache`), `getenv()` does not see `.env`. Build `ConfigurationClientConfig` from your own config there instead of calling `fromEnvironment()`. Pass keepalives and retry settings through that constructor, as in the Laravel snippet above.

## Smoke test

Requires PHP `grpc` extension, a running configuration service, and `CONFIG_SERVICE_INSECURE=true` for local plaintext.

```bash
CONFIG_SERVICE_INSECURE=true php bin/smoke.php
```

## Port CSIQ flags and fetch practice vs user

`practice_configuration_mappings` / `user_configuration_mappings` become definitions named after the column (`is_memo_enabled`) (booleans → feature toggles, other columns → configs). Values are upserted over gRPC. `account_id` is CSIQ `practice_id`; user rows pass `userId:`.

**From** `testData/` **CSVs** (default; dumps of PCM + UCM):

```bash
export CONFIG_SERVICE_INSECURE=true
php bin/port-csiq-flags.php
php bin/fetch-csiq-flags.php --practice-id=75025 --user-id=75015
php bin/fetch-csiq-flags.php --practice-id=75025 --user-id=75015 --region-id=1
# defaults to those ids if omitted; --region-id / CSIQ_CONFIG_REGION_ID is passed as locationGroupId on every read
```

`--practice-id` / `--user-id` filter the dump. `--csv-dir=` overrides `testData/`.

**From a JSON fixture:**

```bash
export CONFIG_SERVICE_INSECURE=true
php bin/port-csiq-flags.php --fixture=fixtures/sample-csiq-flags.json
php bin/fetch-csiq-flags.php --practice-id=1 --user-id=10
```

**From CSIQ MariaDB:**

```bash
export CONFIG_SERVICE_INSECURE=true
export CSIQ_DB_HOST=127.0.0.1 CSIQ_DB_PORT=3306 CSIQ_DB_NAME=laravel CSIQ_DB_USER=<user> CSIQ_DB_PASSWORD=<password>
export CONFIG_DB_HOST=127.0.0.1 CONFIG_DB_PORT=11053 CONFIG_DB_NAME=Configuration CONFIG_DB_USER=<user> CONFIG_DB_PASSWORD=<password>
php bin/port-csiq-flags.php --practice-id=1
php bin/fetch-csiq-flags.php --practice-id=1 --user-id=10
```

Optional `--region-id=1` (or `CSIQ_CONFIG_REGION_ID`) stores practice flags at the LocationGroup layer. Fetch takes the same flag and passes it as `locationGroupId` on every read. Preview with `--dry-run`. After new definitions, the port script hits `http://127.0.0.1:8080/api/v1/warmup` and fails unless that returns HTTP 200. Existing `ConfigDefinition` rows are left alone; a type or override-level mismatch aborts.

| Variable | Default |
|---|---|
| `CSIQ_DB_*` | `127.0.0.1:3306` / `laravel` / `root` / `secret` |
| `CONFIG_DB_*` | `127.0.0.1:11053` / `Configuration` / `root` / `password` |
| `CONFIG_WARMUP_URL` | `http://127.0.0.1:8080/api/v1/warmup` |
