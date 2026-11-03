<?php

declare(strict_types=1);

/**
 * Writes the PHPUnit integration fixture into a running configuration service.
 * Definitions go in via SQL (no gRPC create-definition RPC). Values go over gRPC.
 *
 *   php bin/seed-test-fixtures.php
 *   php bin/seed-test-fixtures.php --dry-run
 *
 * Needs CONFIG_DB_* (or docker exec into CONFIG_DB_CONTAINER) and CONFIG_SERVICE_URI.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

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
    sleep(2);
}

$client = ConfigurationClientFactory::create();
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
