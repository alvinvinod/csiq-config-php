# csiq/config-php

PHP gRPC client for CareStack Configuration. Depend on `IConfigurationClient`. Create **one client per process** and inject it. App classes must not call the factory.

The service resolves override layers. This client does not. Each public method is one unary RPC. Prefer `getAll($casTenantId, $names)` over looping `isFeatureEnabled()`.

A down service throws `ConfigurationException`. It does not return `false` or a cached flag. There is no client-side cache. `casTenantId` is the CAS tenant id.

Laravel apps: use [`csiq/config-laravel`](../csiq-config-laravel), not `fromEnvironment()`.

## Install

```bash
composer require csiq/config-php
```

Needs PHP 8.2+ and **`ext-grpc`**. Composer cannot install the extension.

## How to use

```php
use Csiq\Configuration\ConfigurationClientFactory;
use Csiq\Configuration\IConfigurationClient;

$client = ConfigurationClientFactory::create(); // CONFIG_SERVICE_* + TLS PEM
$memoOn = $client->isFeatureEnabled('is_memo_enabled', $casTenantId);
$set = $client->getAll($casTenantId, ['is_memo_enabled', 'dni_regex'], userId: $userId);
```

Pass an explicit `ConfigurationClientConfig` when the process does not expose `.env` to `getenv()` (Laravel `config:cache`). Reuse the client. Do not construct `ConfigurationClient` in a controller.

Optional second argument to `create()`: a PSR-3 logger. Failed attempts log `warning` (will retry) or `error` (give up).

## Supported operations

Optional ids (`userId`, `locationId`, `locationGroupId`, `attributeArea`) narrow the override layer. Omitted ids are not sent. Scope defaults to `ConfigDefinitionScope::SERVER`.

### Reads

| Method | Use when | Missing name / no stored row |
| --- | --- | --- |
| `isFeatureEnabled($name, $casTenantId, …)` | One **feature** toggle | `false`. Off, defined-but-empty, and unknown name are not distinguishable. A config or dev toggle name fails the call. |
| `isDevToggleEnabled($name, $casTenantId, …)` | One **dev** toggle | Same as `isFeatureEnabled`: `false`. |
| `tryGet($name, $casTenantId, …)` | One value, any type | `null` if the name is not a definition, or a non-toggle config has no value. A feature/dev toggle with no row is `false`, not `null`. |
| `getConfigurationDetails($name, $casTenantId, …)` | Winner plus which layer it came from | Throws `ConfigurationException`. Prefer `tryGet` when missing is expected. |
| `getAll($casTenantId, $names = [], …)` | Several keys, **one RPC** | Named list → `GetAllTogglesAndConfigurationsByNames`. Empty `$names` → every definition; use for dumps, not a request path. Toggles with no row are `false`. Configs and unknown names are **omitted**. |
| `getAllAccountConfigurations($name, $casTenantId, $overrideLevels, …)` | Stored rows at given `OverrideLevel`s, not the winner | A toggle with no stored value is absent here even though `isFeatureEnabled` would be `false`. |

```php
$set = $client->getAll($casTenantId, ['is_memo_enabled', 'dni_regex'], userId: $userId);
$memoOn = $set->toggles['is_memo_enabled'] ?? false;
$regex = $set->configurations['dni_regex'] ?? null;
// missing vs off: array_key_exists('dni_regex', $set->configurations)
```

### Writes

The definition must already exist. These calls do not create one.

| Method | Effect |
| --- | --- |
| `upsert($name, $casTenantId, $value, $editedByUserId, …)` | Insert or replace at that layer. `bool` → boolean, `int` → number, `string` → string, anything else → JSON. A lost response can be retried, so a write may be sent more than once. |
| `delete($name, $casTenantId, $deletedByUserId, …)` | Remove the stored value. The definition stays. A later feature/dev toggle read is `false`. |

`isFeatureEnabled` returning `false` is a **successful** RPC. A dead service throws.

## Environment

Used by `ConfigurationClientConfig::fromEnvironment()` / `ConfigurationClientFactory::create()` with no config.

| Variable | Default |
| --- | --- |
| `CONFIG_SERVICE_URI` | `127.0.0.1:8081` |
| `CONFIG_SERVICE_INSECURE` | `false` |
| `CONFIG_SERVICE_ROOT_CERT_PATH` | required for TLS. PEM of the CA (or self-signed server cert). |
| `CONFIG_SERVICE_TIMEOUT_MS` | `10000` |
| `CONFIG_SERVICE_RETRY_MAX_ATTEMPTS` | `3` |
| `CONFIG_SERVICE_RETRY_INITIAL_BACKOFF_MS` | `250` |
| `CONFIG_SERVICE_RETRY_MAX_BACKOFF_MS` | `250` |
| `CONFIG_SERVICE_RETRY_BACKOFF_MULTIPLIER` | `1` |
| `CONFIG_SERVICE_KEEPALIVE_TIME_MS` | `60000` |
| `CONFIG_SERVICE_KEEPALIVE_TIMEOUT_MS` | `10000` |

TLS always needs a PEM. This client (`ext-grpc` / C-core `ChannelCredentials::createSsl`) does not use the OS trust store. `insecure=true` is plaintext HTTP/2 and skips the PEM.

Local `dotnet run` gRPC is HTTPS HTTP/2 on **7154**. Port **5295** is HTTP/1.1 and cannot serve gRPC.

Retries: `INTERNAL`, `UNKNOWN`, `UNAVAILABLE`; 3 attempts; 250ms equal jitter. `TIMEOUT_MS` covers attempts and backoff. There is no circuit breaker (PHP-FPM workers cannot share breaker state).

Scripts talking to a non-loopback host need `--allow-remote` or `CONFIG_SERVICE_ALLOW_REMOTE=true`. Laravel apps: the adapter rejects loopback URIs and `insecure=true` outside `local` / `testing`.

## Tests and smoke

```bash
composer test          # unit; no live service
php bin/smoke.php      # live HTTPS localhost:7154; exports the ASP.NET cert unless CONFIG_SERVICE_ROOT_CERT_PATH is set
```

`SMOKE_TENANT_ID`, `SMOKE_FLAG`, `SMOKE_WRITE=true` match the Node smoke.

In tests, bind a fake: `Csiq\Configuration\Testing\FakeConfigurationClient`.
