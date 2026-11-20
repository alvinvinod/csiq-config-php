<?php

declare(strict_types=1);

namespace Csiq\Configuration;

use RuntimeException;

/**
 * Host checks for scripts. Treats 127.0.0.0/8, 0.0.0.0, ::1, and localhost as loopback.
 * Laravel env policy (local/testing vs production) lives in csiq-config-laravel.
 */
final class ProductionGuard
{
    public static function assertRemoteAllowed(string $uri, bool $insecure, bool $allowRemote): void
    {
        if (self::isLocalHost($uri)) {
            return;
        }
        if (!$allowRemote) {
            throw new RuntimeException(
                'csiq-config uri is not loopback; pass --allow-remote or set CONFIG_SERVICE_ALLOW_REMOTE=true',
            );
        }
        if ($insecure) {
            throw new RuntimeException('csiq-config insecure must be false for a non-loopback uri');
        }
    }

    public static function allowRemoteRequested(bool $cliFlag = false): bool
    {
        if ($cliFlag) {
            return true;
        }
        $value = getenv('CONFIG_SERVICE_ALLOW_REMOTE');
        if ($value === false || $value === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function isLocalHost(string $uri): bool
    {
        $host = self::hostFromUri($uri);
        if ($host === '') {
            return false;
        }
        if ($host === 'localhost' || $host === 'localhost.') {
            return true;
        }
        if ($host === '::1' || $host === '0:0:0:0:0:0:0:1') {
            return true;
        }
        if (str_starts_with($host, '::ffff:')) {
            return self::isLocalIpv4(substr($host, 7));
        }

        return self::isLocalIpv4($host);
    }

    public static function hostFromUri(string $uri): string
    {
        $trimmed = trim($uri);
        if ($trimmed === '') {
            return '';
        }
        $candidate = str_contains($trimmed, '://') ? $trimmed : '//' . $trimmed;
        $host = parse_url($candidate, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        $host = strtolower($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    private static function isLocalIpv4(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        if ($host === '0.0.0.0') {
            return true;
        }
        $long = ip2long($host);

        return $long !== false && ($long & 0xFF000000) === 0x7F000000;
    }
}
