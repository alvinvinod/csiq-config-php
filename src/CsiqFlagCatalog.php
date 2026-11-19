<?php

declare(strict_types=1);

namespace Csiq\Configuration;

use PDO;
use RuntimeException;

/**
 * Maps CSIQ PCM/UCM columns onto CareStack ConfigDefinition names and types.
 */
final class CsiqFlagCatalog
{
    /** @var list<string> */
    private const SKIP = [
        'id',
        'cas_tenant_id',
        'user_id',
        'culture_id',
        'created_at',
        'updated_at',
        'created_on',
        'updated_on',
        'created_by',
        'last_updated_by',
        'last_updated_on',
        'vm_salt',
        'asterisk_media_ip',
    ];

    public static function isSkipped(string $column): bool
    {
        return in_array($column, self::SKIP, true);
    }

    /**
     * @return array{name: string, column: string, attributeType: int, isFeature: int, overrideLevel: int, value: mixed}|null
     */
    public static function describe(string $column, mixed $value, int $overrideLevel, ?string $mysqlType = null): ?array
    {
        if (self::isSkipped($column) || $value === null || $value === '') {
            return null;
        }

        $attributeType = self::attributeTypeFor($column, $mysqlType, $value);
        $phpValue = self::valueForType($attributeType, $value);
        if ($phpValue === null && $attributeType !== AttributeType::JSON) {
            return null;
        }
        if ($attributeType === AttributeType::JSON && $phpValue === null && !self::isJsonNull($value)) {
            return null;
        }

        return [
            'name' => $column,
            'column' => $column,
            'attributeType' => $attributeType,
            'isFeature' => $attributeType === AttributeType::BOOLEAN ? 2 : 0,
            'overrideLevel' => $overrideLevel,
            'value' => $phpValue,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{name: string, column: string, attributeType: int, isFeature: int, overrideLevel: int, value: mixed}>
     */
    public static function describeRow(array $row, int $overrideLevel, array $mysqlTypes = []): array
    {
        $out = [];
        foreach ($row as $column => $value) {
            $flag = self::describe((string) $column, $value, $overrideLevel, $mysqlTypes[(string) $column] ?? null);
            if ($flag !== null) {
                $out[] = $flag;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function loadCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read ' . $path);
        }
        try {
            $header = fgetcsv($handle);
            if ($header === false || $header === [null] || $header === []) {
                throw new RuntimeException('Empty CSV header in ' . $path);
            }
            $rows = [];
            while (($data = fgetcsv($handle)) !== false) {
                if ($data === [null] || $data === []) {
                    continue;
                }
                if (count($data) !== count($header)) {
                    $data = array_pad($data, count($header), '');
                    $data = array_slice($data, 0, count($header));
                }
                $rows[] = array_combine($header, $data) ?: [];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    public static function pdo(string $prefix): PDO
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('pdo_mysql is not installed. Use --fixture for values, or install php-mysql. Definitions can still use CONFIG_DB_CONTAINER.');
        }

        $host = self::env($prefix . 'HOST', '127.0.0.1');
        $port = self::env($prefix . 'PORT', $prefix === 'CONFIG_DB_' ? '11053' : '3306');
        $name = self::env($prefix . 'NAME', $prefix === 'CONFIG_DB_' ? 'Configuration' : 'laravel');
        $user = self::env($prefix . 'USER', 'root');
        $password = self::env($prefix . 'PASSWORD', $prefix === 'CONFIG_DB_' ? 'password' : 'secret');

        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    public static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

    /** @return array<string, string> */
    public static function columnTypes(PDO $db, string $table): array
    {
        $types = [];
        foreach ($db->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`') as $col) {
            $types[(string) $col['Field']] = strtolower((string) $col['Type']);
        }

        return $types;
    }

    public static function ensureDefinition(array $flag): void
    {
        self::ensureDefinitions([$flag]);
    }

    /**
     * Inserts missing ConfigDefinition rows. Existing names are left alone when
     * AttributeTypeID and OverRideLevelID match; a mismatch aborts.
     *
     * @param list<array{name: string, column: string, attributeType: int, isFeature: int, overrideLevel: int}> $flags
     */
    public static function ensureDefinitions(array $flags): void
    {
        if ($flags === []) {
            return;
        }
        $names = [];
        foreach ($flags as $flag) {
            $names[] = (string) $flag['name'];
        }
        $sql = [];
        foreach (self::definitionsToInsert($flags, self::existingDefinitions($names)) as $flag) {
            $sql[] = sprintf(
                'INSERT INTO ConfigDefinition (
                    AttributeName, AttributeTypeID, OverRideLevelID, AttributeAreaID,
                    AttributeScopeID, IsFeature, Description, CreatedBy, LastUpdatedBy
                ) VALUES (%s, %d, %d, 1, 2, %d, %s, \'csiq-port\', \'csiq-port\')',
                self::quote((string) $flag['name']),
                (int) $flag['attributeType'],
                (int) $flag['overrideLevel'],
                (int) $flag['isFeature'],
                self::quote('CSIQ ' . $flag['column']),
            );
        }
        if ($sql !== []) {
            self::execConfigSql(implode(";\n", $sql));
        }
    }

    /**
     * @param list<array{name: string, column: string, attributeType: int, isFeature: int, overrideLevel: int}> $flags
     * @param array<string, array{attributeType: int, overrideLevel: int}> $existing
     *
     * @return list<array{name: string, column: string, attributeType: int, isFeature: int, overrideLevel: int}>
     */
    public static function definitionsToInsert(array $flags, array $existing): array
    {
        $wanted = [];
        foreach ($flags as $flag) {
            $name = (string) $flag['name'];
            if (isset($wanted[$name])) {
                if (
                    (int) $wanted[$name]['attributeType'] !== (int) $flag['attributeType']
                    || (int) $wanted[$name]['overrideLevel'] !== (int) $flag['overrideLevel']
                ) {
                    throw new RuntimeException(sprintf(
                        'ConfigDefinition %s has conflicting type/override in this batch',
                        $name,
                    ));
                }
                continue;
            }
            $wanted[$name] = $flag;
        }

        $insert = [];
        foreach ($wanted as $name => $flag) {
            if (!isset($existing[$name])) {
                $insert[] = $flag;
                continue;
            }
            $have = $existing[$name];
            if (
                (int) $have['attributeType'] !== (int) $flag['attributeType']
                || (int) $have['overrideLevel'] !== (int) $flag['overrideLevel']
            ) {
                throw new RuntimeException(sprintf(
                    'ConfigDefinition %s already exists as type=%d override=%d; refusing type=%d override=%d',
                    $name,
                    $have['attributeType'],
                    $have['overrideLevel'],
                    $flag['attributeType'],
                    $flag['overrideLevel'],
                ));
            }
        }

        return $insert;
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, array{attributeType: int, overrideLevel: int}>
     */
    public static function existingDefinitions(array $names): array
    {
        $names = array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
        if ($names === []) {
            return [];
        }
        $in = implode(', ', array_map(self::quote(...), $names));
        $rows = self::queryConfigSql(
            "SELECT AttributeName, AttributeTypeID, OverRideLevelID FROM ConfigDefinition WHERE AttributeName IN ({$in})",
        );
        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['AttributeName'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[$name] = [
                'attributeType' => (int) $row['AttributeTypeID'],
                'overrideLevel' => (int) $row['OverRideLevelID'],
            ];
        }

        return $out;
    }

    public static function execConfigSql(string $sql): void
    {
        if (in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::pdo('CONFIG_DB_')->exec($sql);

            return;
        }

        self::mysqlCli($sql);
    }

    public static function warmup(?string $url = null): void
    {
        $url ??= self::env('CONFIG_WARMUP_URL', 'http://127.0.0.1:5295/api/v1/warmup');
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        self::assertWarmupSucceeded($url, $body, $http_response_header ?? null);
    }

    /**
     * @param list<string>|null $responseHeaders
     */
    public static function assertWarmupSucceeded(string $url, string|false $body, ?array $responseHeaders): void
    {
        if ($body === false) {
            throw new RuntimeException('Warmup request failed: ' . $url);
        }
        $statusLine = $responseHeaders[0] ?? '';
        // Local Kestrel returns 204 No Content. Mapped Docker HTTP may return 200.
        if (preg_match('#^HTTP/\S+\s+(200|204)\b#', $statusLine) !== 1) {
            throw new RuntimeException(sprintf(
                'Warmup expected HTTP 200 or 204 from %s, got %s',
                $url,
                $statusLine !== '' ? $statusLine : 'no response',
            ));
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private static function queryConfigSql(string $sql): array
    {
        if (in_array('mysql', PDO::getAvailableDrivers(), true)) {
            return self::pdo('CONFIG_DB_')->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }

        return self::parseMysqlBatchTable(self::mysqlCli($sql));
    }

    private static function mysqlCli(string $sql): string
    {
        $container = self::env('CONFIG_DB_CONTAINER', 'configuration-service-database-1');
        $user = self::env('CONFIG_DB_USER', 'root');
        $password = self::env('CONFIG_DB_PASSWORD', 'password');
        $name = self::env('CONFIG_DB_NAME', 'Configuration');
        $cmd = [
            'docker', 'exec', '-i', $container,
            'mysql', '-B', '-u' . $user, '-p' . $password, $name,
        ];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to exec mysql in ' . $container);
        }
        fwrite($pipes[0], $sql . ";\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new RuntimeException(trim($stderr . $stdout) ?: 'mysql failed');
        }

        return (string) $stdout;
    }

    /**
     * @return list<array<string, string>>
     */
    private static function parseMysqlBatchTable(string $out): array
    {
        $out = trim($out);
        if ($out === '') {
            return [];
        }
        $lines = preg_split("/\r\n|\n|\r/", $out);
        if ($lines === false || $lines === []) {
            return [];
        }
        $header = explode("\t", array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $cols = explode("\t", $line);
            if (count($cols) < count($header)) {
                $cols = array_pad($cols, count($header), '');
            }
            $rows[] = array_combine($header, array_slice($cols, 0, count($header))) ?: [];
        }

        return $rows;
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }

    private static function attributeTypeFor(string $column, ?string $mysqlType, mixed $value): int
    {
        if ($mysqlType !== null) {
            return self::mysqlAttributeType($mysqlType);
        }
        if (is_bool($value) || self::looksBooleanName($column)) {
            return AttributeType::BOOLEAN;
        }
        if (is_int($value)) {
            return AttributeType::NUMBER;
        }
        if (is_array($value) || is_object($value)) {
            return AttributeType::JSON;
        }
        if (is_string($value) && $value !== '' && ($value[0] === '[' || $value[0] === '{')) {
            try {
                json_decode($value, true, 512, JSON_THROW_ON_ERROR);

                return AttributeType::JSON;
            } catch (\JsonException) {
                return AttributeType::STRING;
            }
        }

        return AttributeType::STRING;
    }

    private static function mysqlAttributeType(string $mysqlType): int
    {
        $type = strtolower(trim($mysqlType));
        if (str_starts_with($type, 'bit') || preg_match('/^tinyint(\(1\))?(?:\s|$)/', $type) === 1) {
            return AttributeType::BOOLEAN;
        }
        if (preg_match('/tinyint|smallint|mediumint|bigint|^int\b/', $type) === 1) {
            return AttributeType::NUMBER;
        }
        if (preg_match('/decimal|float|double|real/', $type) === 1) {
            // NUMBER decodes as int; this client encodes floats as JSON.
            return AttributeType::JSON;
        }
        if (str_contains($type, 'json')) {
            return AttributeType::JSON;
        }

        return AttributeType::STRING;
    }

    private static function looksBooleanName(string $column): bool
    {
        if (preg_match('/^(is_|has_|is[A-Z])/', $column) === 1) {
            return true;
        }
        if (str_ends_with($column, '_enabled') || str_ends_with($column, '_visible') || str_ends_with($column, '_shown')) {
            return true;
        }

        return str_starts_with($column, 'show_');
    }

    private static function valueForType(int $attributeType, mixed $value): mixed
    {
        return match ($attributeType) {
            AttributeType::BOOLEAN => self::toBoolStrict($value),
            AttributeType::NUMBER => self::toIntStrict($value),
            AttributeType::JSON => self::toJsonValue($value),
            default => self::toStringStrict($value),
        };
    }

    private static function toBoolStrict(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 1.0) {
            return true;
        }
        if ($value === 0 || $value === 0.0) {
            return false;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private static function toIntStrict(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && $value === (float) (int) $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function toStringStrict(mixed $value): ?string
    {
        if (is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        return (string) $value;
    }

    private static function toJsonValue(mixed $value): mixed
    {
        if (is_array($value) || is_object($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function isJsonNull(mixed $value): bool
    {
        return $value === null || $value === 'null';
    }
}
