<?php

declare(strict_types=1);

/**
 * Copy CSIQ practice_configuration_mappings + user_configuration_mappings
 * into CareStack Configuration (definitions in MySQL, values via gRPC upsert).
 *
 * Default: testData/*.csv dumps.
 *   php bin/port-csiq-flags.php [--tenant-id=1] [--user-id=00100]
 *
 * From CSIQ MariaDB:
 *   php bin/port-csiq-flags.php --from-db [--tenant-id=1]
 *
 * From a JSON fixture:
 *   php bin/port-csiq-flags.php --fixture=fixtures/sample-csiq-flags.json
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Csiq\Configuration\ConfigurationClientFactory;
use Csiq\Configuration\CsiqFlagCatalog;
use Csiq\Configuration\IConfigurationClient;
use Csiq\Configuration\OverrideLevel;

final class CsiqFlagWriter
{
    public function __construct(private IConfigurationClient $config)
    {
    }

    /**
     * @param list<array{name: string, value: mixed}> $flags
     */
    public function upsertPracticeFlags(int $casTenantId, array $flags, int $editedBy, ?int $locationGroupId = null): int
    {
        $n = 0;
        foreach ($flags as $flag) {
            $this->config->upsert(
                $flag['name'],
                $casTenantId,
                $flag['value'],
                $editedBy,
                locationGroupId: $locationGroupId,
            );
            $n++;
        }

        return $n;
    }

    /**
     * @param list<array{name: string, value: mixed}> $flags
     */
    public function upsertUserFlags(int $casTenantId, int $userId, array $flags, int $editedBy): int
    {
        $n = 0;
        foreach ($flags as $flag) {
            $this->config->upsert(
                $flag['name'],
                $casTenantId,
                $flag['value'],
                $editedBy,
                userId: $userId,
            );
            $n++;
        }

        return $n;
    }
}

$opts = getopt('', ['fixture:', 'csv-dir:', 'tenant-id:', 'user-id:', 'region-id:', 'from-db', 'dry-run', 'skip-warmup']);
$fixture = $opts['fixture'] ?? null;
$csvDir = $opts['csv-dir'] ?? dirname(__DIR__) . '/testData';
$fromDb = array_key_exists('from-db', $opts);
$onlyTenant = isset($opts['tenant-id']) ? (int) $opts['tenant-id'] : null;
$onlyUser = isset($opts['user-id']) ? (int) $opts['user-id'] : null;
$regionId = isset($opts['region-id'])
    ? (int) $opts['region-id']
    : (CsiqFlagCatalog::env('CSIQ_CONFIG_REGION_ID') !== '' ? (int) CsiqFlagCatalog::env('CSIQ_CONFIG_REGION_ID') : null);
$dryRun = array_key_exists('dry-run', $opts);
$skipWarmup = array_key_exists('skip-warmup', $opts);

$practiceLevel = $regionId !== null ? OverrideLevel::LOCATION_GROUP : OverrideLevel::PRACTICE;

$practiceCsv = $csvDir . '/practice_configuration_mappings.csv';
$userCsv = $csvDir . '/user_configuration_mappings.csv';
$practiceTypes = [];
$userTypes = [];

if ($fixture !== null) {
    $payload = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
    $practiceRows = $payload['practices'] ?? [];
    $userRows = $payload['users'] ?? [];
} elseif (!$fromDb && is_file($practiceCsv) && is_file($userCsv)) {
    $practiceRows = CsiqFlagCatalog::loadCsv($practiceCsv);
    $userRows = CsiqFlagCatalog::loadCsv($userCsv);
    echo "loaded CSV from {$csvDir}\n";
} else {
    $csiq = CsiqFlagCatalog::pdo('CSIQ_DB_');
    $practiceTypes = CsiqFlagCatalog::columnTypes($csiq, 'practice_configuration_mappings');
    $userTypes = CsiqFlagCatalog::columnTypes($csiq, 'user_configuration_mappings');
    $practiceSql = 'SELECT * FROM practice_configuration_mappings';
    $practiceParams = [];
    if ($onlyTenant !== null) {
        $practiceSql .= ' WHERE cas_tenant_id = ?';
        $practiceParams[] = $onlyTenant;
    }
    $stmt = $csiq->prepare($practiceSql);
    $stmt->execute($practiceParams);
    $practiceRows = $stmt->fetchAll();

    $userSql = 'SELECT * FROM user_configuration_mappings';
    $userParams = [];
    $userWhere = [];
    if ($onlyTenant !== null) {
        $userWhere[] = 'cas_tenant_id = ?';
        $userParams[] = $onlyTenant;
    }
    if ($onlyUser !== null) {
        $userWhere[] = 'user_id = ?';
        $userParams[] = $onlyUser;
    }
    if ($userWhere !== []) {
        $userSql .= ' WHERE ' . implode(' AND ', $userWhere);
    }
    $stmt = $csiq->prepare($userSql);
    $stmt->execute($userParams);
    $userRows = $stmt->fetchAll();
}

if ($onlyTenant !== null) {
    $practiceRows = array_values(array_filter(
        $practiceRows,
        static fn (array $row): bool => (int) ($row['cas_tenant_id'] ?? 0) === $onlyTenant,
    ));
    $userRows = array_values(array_filter(
        $userRows,
        static fn (array $row): bool => (int) ($row['cas_tenant_id'] ?? 0) === $onlyTenant,
    ));
}
if ($onlyUser !== null) {
    $userRows = array_values(array_filter(
        $userRows,
        static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === $onlyUser,
    ));
}

$definitions = [];
$practiceFlags = [];
foreach ($practiceRows as $row) {
    $casTenantId = (int) ($row['cas_tenant_id'] ?? 0);
    if ($casTenantId < 1) {
        continue;
    }
    $flags = CsiqFlagCatalog::describeRow($row, $practiceLevel, $practiceTypes);
    $practiceFlags[$casTenantId] = $flags;
    foreach ($flags as $flag) {
        $definitions[$flag['name']] = $flag;
    }
}

$userFlags = [];
foreach ($userRows as $row) {
    $casTenantId = (int) ($row['cas_tenant_id'] ?? 0);
    $userId = (int) ($row['user_id'] ?? 0);
    if ($casTenantId < 1 || $userId < 1) {
        continue;
    }
    $flags = CsiqFlagCatalog::describeRow($row, OverrideLevel::USER, $userTypes);
    $userFlags[] = ['cas_tenant_id' => $casTenantId, 'user_id' => $userId, 'flags' => $flags];
    foreach ($flags as $flag) {
        $definitions[$flag['name']] = $flag;
    }
}

echo sprintf(
    "practices=%d users=%d definitions=%d regionId=%s dryRun=%s\n",
    count($practiceFlags),
    count($userFlags),
    count($definitions),
    $regionId === null ? 'none (Practice layer)' : (string) $regionId,
    $dryRun ? 'yes' : 'no',
);

if ($definitions === []) {
    fwrite(STDERR, "No flags found. Check CSIQ DB env or --fixture.\n");
    exit(1);
}

if ($dryRun) {
    foreach ($definitions as $flag) {
        echo sprintf(
            "  def %s type=%d isFeature=%d override=%d\n",
            $flag['name'],
            $flag['attributeType'],
            $flag['isFeature'],
            $flag['overrideLevel'],
        );
    }
    exit(0);
}

CsiqFlagCatalog::ensureDefinitions(array_values($definitions));
echo 'wrote ' . count($definitions) . " ConfigDefinition rows\n";

if (!$skipWarmup) {
    CsiqFlagCatalog::warmup();
    sleep(2);
}

$writer = new CsiqFlagWriter(ConfigurationClientFactory::create());
$upserts = 0;
foreach ($practiceFlags as $casTenantId => $flags) {
    $upserts += $writer->upsertPracticeFlags($casTenantId, $flags, 1, $regionId);
}
foreach ($userFlags as $entry) {
    $upserts += $writer->upsertUserFlags($entry['cas_tenant_id'], $entry['user_id'], $entry['flags'], $entry['user_id']);
}
echo "upserted {$upserts} values via gRPC\n";
echo "fetch with: php bin/fetch-csiq-flags.php --tenant-id=<id> --user-id=<id>\n";
