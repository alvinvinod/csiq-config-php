<?php

declare(strict_types=1);

/**
 * Hit a running CareStack configuration service with IConfigurationClient.
 *
 *   php bin/smoke.php
 *
 * Local `dotnet run --project CareStack.Configuration.Server` serves gRPC over
 * HTTPS HTTP/2 on localhost:7154. The HTTP URL on 5295 is HTTP/1.1 and will
 * fail with a protocol error. Override with CONFIG_SERVICE_URI.
 *
 * Plaintext HTTP/2 (mapped 8081):
 *   CONFIG_SERVICE_URI=127.0.0.1:8081 CONFIG_SERVICE_INSECURE=true php bin/smoke.php
 *
 * Optional: SMOKE_PRACTICE_ID (10001), SMOKE_FLAG (first toggle from getAll, else EnableFoo),
 * SMOKE_WRITE=true to flip and restore a feature toggle.
 * CONFIG_SERVICE_ROOT_CERT_PATH overrides the exported ASP.NET HTTPS cert.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Csiq\Configuration\ConfigurationClientConfig;
use Csiq\Configuration\ConfigurationClientFactory;
use Csiq\Configuration\ConfigurationException;
use Csiq\Configuration\IConfigurationClient;
use Csiq\Configuration\OverrideLevel;

final class ConfigurationSmoke
{
    public function __construct(private IConfigurationClient $config)
    {
    }

    public function run(int $practiceId, bool $write): int
    {
        $dump = $this->config->getAll($practiceId);
        $toggleNames = array_keys($dump->toggles);
        $configNames = array_keys($dump->configurations);
        echo sprintf('getAll(): %d toggles, %d configurations', count($toggleNames), count($configNames)) . PHP_EOL;
        if ($toggleNames !== []) {
            $preview = array_slice($toggleNames, 0, 8);
            echo '  toggles: ' . implode(', ', $preview) . (count($toggleNames) > 8 ? ', …' : '') . PHP_EOL;
        }

        $flag = getenv('SMOKE_FLAG') ?: ($toggleNames[0] ?? 'EnableFoo');
        echo 'flag: ' . $flag . PHP_EOL;

        $named = $this->config->getAll($practiceId, [$flag]);
        echo sprintf(
            'getAll(names): toggle=%s config=%s',
            show($named->toggles[$flag] ?? null),
            array_key_exists($flag, $named->configurations) ? show($named->configurations[$flag]) : '(omitted)',
        ) . PHP_EOL;

        $enabled = $this->config->isFeatureEnabled($flag, $practiceId);
        echo 'isFeatureEnabled(): ' . show($enabled) . PHP_EOL;

        try {
            $dev = $this->config->isDevToggleEnabled($flag, $practiceId);
            echo 'isDevToggleEnabled(): ' . show($dev) . PHP_EOL;
        } catch (ConfigurationException $err) {
            echo sprintf('isDevToggleEnabled(): %s (code=%d)', $err->getMessage(), $err->getCode()) . PHP_EOL;
        }

        echo 'tryGet(): ' . show($this->config->tryGet($flag, $practiceId)) . PHP_EOL;

        try {
            $details = $this->config->getConfigurationDetails($flag, $practiceId);
            echo sprintf(
                'getConfigurationDetails(): name=%s value=%s priority=%d type=%d accountId=%s',
                $details->name,
                show($details->value),
                $details->priority,
                $details->type,
                $details->accountId ?? 'null',
            ) . PHP_EOL;
        } catch (ConfigurationException $err) {
            echo sprintf('getConfigurationDetails(): throws code=%d %s', $err->getCode(), $err->getMessage()) . PHP_EOL;
        }

        $rows = $this->config->getAllAccountConfigurations($flag, $practiceId, [
            OverrideLevel::PRACTICE,
            OverrideLevel::VOICESTACK,
        ]);
        echo 'getAllAccountConfigurations(): ' . count($rows) . ' row(s)' . PHP_EOL;
        foreach ($rows as $row) {
            echo sprintf(
                '  priority=%d value=%s accountId=%s userId=%s',
                $row->priority,
                show($row->value),
                $row->accountId ?? 'null',
                $row->userId ?? 'null',
            ) . PHP_EOL;
        }

        try {
            $this->config->getConfigurationDetails('DoesNotExist_csiq_php_smoke', $practiceId);
            fwrite(STDERR, "missing name should have thrown\n");

            return 1;
        } catch (ConfigurationException) {
            echo 'getConfigurationDetails(missing): throws' . PHP_EOL;
        }

        if ($write) {
            $this->config->upsert($flag, $practiceId, !$enabled, 1);
            echo 'after upsert: ' . show($this->config->isFeatureEnabled($flag, $practiceId)) . PHP_EOL;
            $this->config->upsert($flag, $practiceId, $enabled, 1);
            echo 'restored: ' . show($this->config->isFeatureEnabled($flag, $practiceId)) . PHP_EOL;
        }

        return 0;
    }
}

function truthy(mixed $raw): bool
{
    return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
}

function intEnv(string $key, int $fallback): int
{
    $raw = getenv($key);
    if ($raw === false || $raw === '') {
        return $fallback;
    }
    $n = filter_var($raw, FILTER_VALIDATE_INT);
    if ($n === false || $n < 1) {
        throw new InvalidArgumentException(sprintf('%s must be a positive integer, got "%s"', $key, $raw));
    }

    return $n;
}

function writeServerCert(string $target): string
{
    $colon = strrpos($target, ':');
    $hostname = $colon === false ? $target : substr($target, 0, $colon);
    $port = $colon === false ? '7154' : substr($target, $colon + 1);
    $connect = $hostname . ':' . $port;
    $cmd = sprintf(
        'echo | openssl s_client -connect %s -servername %s -showcerts 2>/dev/null',
        escapeshellarg($connect),
        escapeshellarg($hostname),
    );
    $output = shell_exec($cmd);
    if (!is_string($output) || !preg_match('/-----BEGIN CERTIFICATE-----[\s\S]+?-----END CERTIFICATE-----/', $output, $pem)) {
        throw new RuntimeException(
            "could not read TLS cert from {$target}. For plaintext HTTP/2 set CONFIG_SERVICE_INSECURE=true.",
        );
    }
    $certPath = sys_get_temp_dir() . '/csiq-config-smoke-ca.pem';
    file_put_contents($certPath, $pem[0] . "\n");

    return $certPath;
}

function show(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if ($value === null) {
        return 'null';
    }

    return json_encode($value, JSON_THROW_ON_ERROR);
}

$host = getenv('CONFIG_SERVICE_URI') ?: 'localhost:7154';
$insecure = truthy(getenv('CONFIG_SERVICE_INSECURE'));
$rootCertPath = getenv('CONFIG_SERVICE_ROOT_CERT_PATH') ?: ($insecure ? null : writeServerCert($host));
$practiceId = intEnv('SMOKE_PRACTICE_ID', 10001);
$write = truthy(getenv('SMOKE_WRITE'));

echo sprintf(
    'smoke: %s insecure=%s practiceId=%d%s',
    $host,
    $insecure ? 'true' : 'false',
    $practiceId,
    $rootCertPath ? " rootCertPath={$rootCertPath}" : '',
) . PHP_EOL;

$client = ConfigurationClientFactory::create(
    new ConfigurationClientConfig(
        $host,
        $insecure,
        rootCertPath: $rootCertPath,
    ),
);

try {
    exit((new ConfigurationSmoke($client))->run($practiceId, $write));
} catch (ConfigurationException $err) {
    fwrite(STDERR, sprintf("gRPC %d: %s\n", $err->getCode(), $err->getMessage()));
    exit(1);
}
