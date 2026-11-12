<?php

declare(strict_types=1);

/**
 * Writes the PHPUnit integration fixture into a running configuration service.
 * Definitions go in via SQL (no gRPC create-definition RPC). Values go over gRPC.
 *
 *   php bin/seed-test-fixtures.php
 *   php bin/seed-test-fixtures.php --dry-run
 *
 * Needs CONFIG_DB_* (or docker exec into CONFIG_DB_CONTAINER) and a gRPC endpoint.
 * TLS matches smoke: CONFIG_SERVICE_URI (default localhost:7154), PEM from
 * CONFIG_SERVICE_ROOT_CERT_PATH or openssl against that host.
 * Warmup: CONFIG_WARMUP_URL (default http://127.0.0.1:5295/api/v1/warmup).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Csiq\Configuration\ConfigurationClientConfig;
use Csiq\Configuration\ConfigurationClientFactory;
use Csiq\Configuration\CsiqFlagCatalog;

$opts = getopt('', ['fixture:', 'dry-run', 'skip-warmup']);
$path = $opts['fixture'] ?? dirname(__DIR__) . '/tests/fixtures/integration-seed.json';
$payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

$definitions = [];
foreach ($payload['definitions'] as $definition) {
    $definitions[] = $definition + ['column' => $definition['name']];
}

echo sprintf(
    "definitions=%d values=%d accountId=%d dryRun=%s\n",
    count($definitions),
    count($payload['values'] ?? []),
    (int) $payload['accountId'],
    isset($opts['dry-run']) ? 'yes' : 'no',
);

if (isset($opts['dry-run'])) {
    foreach ($definitions as $definition) {
        echo sprintf(
            "  def %s type=%d isFeature=%d override=%d\n",
            $definition['name'],
            $definition['attributeType'],
            $definition['isFeature'],
            $definition['overrideLevel'],
        );
    }
    exit(0);
}

CsiqFlagCatalog::ensureDefinitions($definitions);
echo 'wrote ' . count($definitions) . " ConfigDefinition rows\n";

if (!isset($opts['skip-warmup'])) {
    CsiqFlagCatalog::warmup();
    // New SQL rows reach the running process through CDC, not /warmup.
    sleep(2);
}

$host = getenv('CONFIG_SERVICE_URI') ?: 'localhost:7154';
$insecure = in_array(strtolower(trim((string) getenv('CONFIG_SERVICE_INSECURE'))), ['1', 'true', 'yes', 'on'], true);
$rootCertPath = getenv('CONFIG_SERVICE_ROOT_CERT_PATH') ?: ($insecure ? null : writeServerCert($host));
$client = ConfigurationClientFactory::create(
    new ConfigurationClientConfig($host, $insecure, rootCertPath: $rootCertPath),
);

$accountId = (int) $payload['accountId'];
$userId = (int) $payload['userId'];
$locationGroupId = (int) $payload['locationGroupId'];
$editedBy = (int) $payload['editedBy'];

$upserts = 0;
foreach ($payload['values'] as $row) {
    $client->upsert(
        $row['name'],
        $accountId,
        $row['value'],
        $editedBy,
        userId: ($row['level'] ?? '') === 'user' ? $userId : null,
        locationGroupId: ($row['level'] ?? '') === 'location_group' ? $locationGroupId : null,
    );
    $upserts++;
}
echo "upserted {$upserts} values via gRPC\n";

$cleared = 0;
foreach ($payload['cleared'] ?? [] as $name) {
    try {
        $client->delete($name, $accountId, $editedBy);
        $cleared++;
    } catch (Throwable $e) {
        echo "  skip delete {$name}: {$e->getMessage()}\n";
    }
}
echo "cleared {$cleared} leftover values\n";
echo "run: CONFIG_SERVICE_INTEGRATION=1 vendor/bin/phpunit --testsuite integration\n";

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
