<?php

declare(strict_types=1);

namespace Csiq\Configuration;

use InvalidArgumentException;

final class ConfigurationClientConfig
{
    /**
     * @param int $timeoutMs Deadline for one client call, retries and backoff included.
     * @param int $keepaliveTimeMs HTTP/2 ping interval. Stand-in for CareStack's 60s channel recycle.
     * @param int $keepaliveTimeoutMs How long to wait for a ping ACK before dropping the session.
     * @param string|null $rootCertPath PEM of the CA (or self-signed server cert). Required when insecure is false.
     */
    public function __construct(
        public readonly string $host,
        public readonly bool $insecure = false,
        public readonly int $timeoutMs = 10000,
        public readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        public readonly int $keepaliveTimeMs = 60000,
        public readonly int $keepaliveTimeoutMs = 10000,
        public readonly ?string $rootCertPath = null,
    ) {
        if ($timeoutMs < 1) {
            throw new InvalidArgumentException('timeoutMs must be at least 1');
        }
        if ($keepaliveTimeMs < 1) {
            throw new InvalidArgumentException('keepaliveTimeMs must be at least 1');
        }
        if ($keepaliveTimeoutMs < 1) {
            throw new InvalidArgumentException('keepaliveTimeoutMs must be at least 1');
        }
        if (!$insecure && ($rootCertPath === null || $rootCertPath === '')) {
            throw new InvalidArgumentException('rootCertPath is required for TLS');
        }
    }

    public static function fromEnvironment(): self
    {
        $insecure = getenv('CONFIG_SERVICE_INSECURE');
        $defaults = new RetryPolicy();

        return new self(
            getenv('CONFIG_SERVICE_URI') ?: '127.0.0.1:8081',
            $insecure === false || $insecure === '' ? false : filter_var($insecure, FILTER_VALIDATE_BOOLEAN),
            self::intEnv('CONFIG_SERVICE_TIMEOUT_MS', 10000),
            new RetryPolicy(
                self::intEnv('CONFIG_SERVICE_RETRY_MAX_ATTEMPTS', $defaults->maxAttempts),
                self::intEnv('CONFIG_SERVICE_RETRY_INITIAL_BACKOFF_MS', $defaults->initialBackoffMs),
                self::intEnv('CONFIG_SERVICE_RETRY_MAX_BACKOFF_MS', $defaults->maxBackoffMs),
                self::floatEnv('CONFIG_SERVICE_RETRY_BACKOFF_MULTIPLIER', $defaults->backoffMultiplier),
            ),
            self::intEnv('CONFIG_SERVICE_KEEPALIVE_TIME_MS', 60000),
            self::intEnv('CONFIG_SERVICE_KEEPALIVE_TIMEOUT_MS', 10000),
            self::stringEnv('CONFIG_SERVICE_ROOT_CERT_PATH'),
        );
    }

    private static function stringEnv(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }

    private static function intEnv(string $key, int $default): int
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            throw new InvalidArgumentException(sprintf('%s must be an integer, got "%s"', $key, $value));
        }

        return $int;
    }

    private static function floatEnv(string $key, float $default): float
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        $float = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($float === false) {
            throw new InvalidArgumentException(sprintf('%s must be a number, got "%s"', $key, $value));
        }

        return $float;
    }
}
