<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Unit;

use Csiq\Configuration\AttributeType;
use Csiq\Configuration\CsiqFlagCatalog;
use Csiq\Configuration\OverrideLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CsiqFlagCatalogTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csiq-csv-');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /** @return iterable<string, array{string}> */
    public static function skippedColumns(): iterable
    {
        foreach ([
            'id', 'practice_id', 'user_id', 'culture_id', 'created_at', 'updated_at', 'created_on',
            'updated_on', 'created_by', 'last_updated_by', 'last_updated_on', 'vm_salt', 'asterisk_media_ip',
        ] as $column) {
            yield $column => [$column];
        }
    }

    #[DataProvider('skippedColumns')]
    public function testSkipsBookkeepingAndSecretColumns(string $column): void
    {
        self::assertTrue(CsiqFlagCatalog::isSkipped($column));
        self::assertNull(CsiqFlagCatalog::describe($column, '1', OverrideLevel::PRACTICE));
    }

    public function testSkipsNullAndEmptyValues(): void
    {
        self::assertNull(CsiqFlagCatalog::describe('is_memo_enabled', null, OverrideLevel::PRACTICE));
        self::assertNull(CsiqFlagCatalog::describe('is_memo_enabled', '', OverrideLevel::PRACTICE));
    }

    public function testDefinitionIsNamedAfterTheColumnWithoutPrefix(): void
    {
        self::assertSame(
            [
                'name' => 'is_memo_enabled',
                'column' => 'is_memo_enabled',
                'attributeType' => AttributeType::BOOLEAN,
                'isFeature' => 2,
                'overrideLevel' => OverrideLevel::PRACTICE,
                'value' => true,
            ],
            CsiqFlagCatalog::describe('is_memo_enabled', '1', OverrideLevel::PRACTICE),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function booleanColumnNames(): iterable
    {
        yield 'is_ prefix' => ['is_fax_enabled'];
        yield 'has_ prefix' => ['has_sms'];
        yield 'camel isX' => ['isActive'];
        yield '_enabled suffix' => ['recording_enabled'];
        yield '_visible suffix' => ['billing_visible'];
        yield '_shown suffix' => ['banner_shown'];
        yield 'show_ prefix' => ['show_caller_id'];
    }

    #[DataProvider('booleanColumnNames')]
    public function testBooleanByColumnName(string $column): void
    {
        $flag = CsiqFlagCatalog::describe($column, '0', OverrideLevel::USER);

        self::assertSame(AttributeType::BOOLEAN, $flag['attributeType']);
        self::assertSame(2, $flag['isFeature']);
        self::assertSame(OverrideLevel::USER, $flag['overrideLevel']);
        self::assertFalse($flag['value']);
    }

    public function testBooleanByTinyintType(): void
    {
        $flag = CsiqFlagCatalog::describe('call_recording', '1', OverrideLevel::PRACTICE, 'tinyint(1)');

        self::assertSame(AttributeType::BOOLEAN, $flag['attributeType']);
        self::assertTrue($flag['value']);
    }

    public function testNativeBoolIsAlwaysAToggle(): void
    {
        $flag = CsiqFlagCatalog::describe('recording', true, OverrideLevel::PRACTICE);

        self::assertSame(AttributeType::BOOLEAN, $flag['attributeType']);
        self::assertTrue($flag['value']);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function booleanValues(): iterable
    {
        yield '"1"' => ['1', true];
        yield '"0"' => ['0', false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield '"true"' => ['true', true];
        yield '"false"' => ['false', false];
        yield '"yes"' => ['yes', true];
        yield '"on"' => ['on', true];
    }

    #[DataProvider('booleanValues')]
    public function testBooleanValueParsing(mixed $value, bool $expected): void
    {
        self::assertSame($expected, CsiqFlagCatalog::describe('is_x', $value, OverrideLevel::PRACTICE)['value']);
    }

    public function testBooleanValueThatDoesNotFitIsSkipped(): void
    {
        self::assertNull(CsiqFlagCatalog::describe('is_x', 'maybe', OverrideLevel::PRACTICE));
        self::assertNull(CsiqFlagCatalog::describe('is_x', '2', OverrideLevel::PRACTICE));
        self::assertNull(CsiqFlagCatalog::describe('call_recording', '2', OverrideLevel::PRACTICE, 'tinyint(1)'));
    }

    public function testJsonTextBecomesAJsonConfig(): void
    {
        $flag = CsiqFlagCatalog::describe('transcription_languages', '["en-US","es-US"]', OverrideLevel::PRACTICE);

        self::assertSame(AttributeType::JSON, $flag['attributeType']);
        self::assertSame(0, $flag['isFeature']);
        self::assertSame(['en-US', 'es-US'], $flag['value']);
    }

    public function testInvalidJsonTextStaysAString(): void
    {
        $flag = CsiqFlagCatalog::describe('note', '[not json', OverrideLevel::PRACTICE);

        self::assertSame(AttributeType::STRING, $flag['attributeType']);
        self::assertSame('[not json', $flag['value']);
    }

    public function testJsonColumnTypeIsAJsonConfig(): void
    {
        $flag = CsiqFlagCatalog::describe('settings', '{"a":1}', OverrideLevel::PRACTICE, 'json');

        self::assertSame(AttributeType::JSON, $flag['attributeType']);
        self::assertSame(['a' => 1], $flag['value']);
    }

    public function testIntColumnTypeIsANumberConfig(): void
    {
        $flag = CsiqFlagCatalog::describe('cultures', '3', OverrideLevel::PRACTICE, 'int(11)');

        self::assertSame(AttributeType::NUMBER, $flag['attributeType']);
        self::assertSame(0, $flag['isFeature']);
        self::assertSame(3, $flag['value']);
    }

    public function testNativeIntWithoutTypeIsANumberConfig(): void
    {
        $flag = CsiqFlagCatalog::describe('max_agents', 25, OverrideLevel::PRACTICE);

        self::assertSame(AttributeType::NUMBER, $flag['attributeType']);
        self::assertSame(25, $flag['value']);
    }

    public function testPlainTextIsAStringConfig(): void
    {
        $flag = CsiqFlagCatalog::describe('agent_app_version', '4.2.0', OverrideLevel::PRACTICE, 'varchar(32)');

        self::assertSame(AttributeType::STRING, $flag['attributeType']);
        self::assertSame('4.2.0', $flag['value']);
    }

    public function testNumericCsvTextWithoutTypeStaysAString(): void
    {
        $flag = CsiqFlagCatalog::describe('cultures', '3', OverrideLevel::PRACTICE);

        self::assertSame(AttributeType::STRING, $flag['attributeType']);
        self::assertSame('3', $flag['value']);
    }

    public function testTinyintWiderThanOneIsANumber(): void
    {
        $flag = CsiqFlagCatalog::describe('call_mode', '2', OverrideLevel::PRACTICE, 'tinyint(4)');

        self::assertSame(AttributeType::NUMBER, $flag['attributeType']);
        self::assertSame(0, $flag['isFeature']);
        self::assertSame(2, $flag['value']);
    }

    public function testMysqlTypeWinsOverBooleanColumnName(): void
    {
        $flag = CsiqFlagCatalog::describe('is_memo_enabled', '1', OverrideLevel::PRACTICE, 'varchar(8)');

        self::assertSame(AttributeType::STRING, $flag['attributeType']);
        self::assertSame(0, $flag['isFeature']);
        self::assertSame('1', $flag['value']);
    }

    public function testIntColumnWithAFractionalValueIsSkipped(): void
    {
        self::assertNull(CsiqFlagCatalog::describe('cultures', '1.5', OverrideLevel::PRACTICE, 'int(11)'));
    }

    public function testJsonColumnWithInvalidTextIsSkipped(): void
    {
        self::assertNull(CsiqFlagCatalog::describe('settings', 'not json', OverrideLevel::PRACTICE, 'json'));
    }

    public function testForeignKeyColumnsAreNotSkipped(): void
    {
        $flag = CsiqFlagCatalog::describe('region_id', '12', OverrideLevel::PRACTICE, 'int(11)');

        self::assertNotNull($flag);
        self::assertSame(AttributeType::NUMBER, $flag['attributeType']);
        self::assertSame(12, $flag['value']);
    }

    public function testDecimalColumnIsJsonSoTheUpsertTypeMatches(): void
    {
        $flag = CsiqFlagCatalog::describe('rate', '1.50', OverrideLevel::PRACTICE, 'decimal(5,2)');

        self::assertSame(AttributeType::JSON, $flag['attributeType']);
        self::assertSame(1.5, $flag['value']);
    }

    public function testDescribeRowDropsSkippedAndEmptyColumns(): void
    {
        $flags = CsiqFlagCatalog::describeRow(
            ['id' => '1', 'practice_id' => '10018', 'is_memo_enabled' => '1', 'note' => '', 'cultures' => '3'],
            OverrideLevel::PRACTICE,
            ['cultures' => 'int(11)'],
        );

        self::assertSame(['is_memo_enabled', 'cultures'], array_column($flags, 'name'));
        self::assertSame(3, $flags[1]['value']);
    }

    public function testLoadCsvPadsShortRowsTrimsLongOnesAndSkipsBlankLines(): void
    {
        $path = $this->csv("practice_id,is_memo_enabled,note\n10018,1\n\n10019,0,hi,extra\n");

        self::assertSame(
            [
                ['practice_id' => '10018', 'is_memo_enabled' => '1', 'note' => ''],
                ['practice_id' => '10019', 'is_memo_enabled' => '0', 'note' => 'hi'],
            ],
            CsiqFlagCatalog::loadCsv($path),
        );
    }

    public function testLoadCsvRejectsAnEmptyFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Empty CSV header');

        CsiqFlagCatalog::loadCsv($this->csv(''));
    }

    public function testLoadCsvRejectsAMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read');

        @CsiqFlagCatalog::loadCsv('/nonexistent/csiq.csv');
    }

    private function definition(string $name, int $type, int $override): array
    {
        return [
            'name' => $name,
            'column' => $name,
            'attributeType' => $type,
            'isFeature' => $type === AttributeType::BOOLEAN ? 2 : 0,
            'overrideLevel' => $override,
        ];
    }

    public function testDefinitionsToInsertKeepsNewNamesAndSkipsMatchingExisting(): void
    {
        $memo = $this->definition('is_memo_enabled', AttributeType::BOOLEAN, OverrideLevel::PRACTICE);
        $fax = $this->definition('is_fax_enabled', AttributeType::BOOLEAN, OverrideLevel::PRACTICE);

        self::assertSame(
            [$fax],
            CsiqFlagCatalog::definitionsToInsert(
                [$memo, $fax, $memo],
                ['is_memo_enabled' => ['attributeType' => AttributeType::BOOLEAN, 'overrideLevel' => OverrideLevel::PRACTICE]],
            ),
        );
    }

    public function testDefinitionsToInsertAbortsWhenTypeWouldChange(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is_memo_enabled already exists as type=1 override=2; refusing type=4 override=2');

        CsiqFlagCatalog::definitionsToInsert(
            [$this->definition('is_memo_enabled', AttributeType::NUMBER, OverrideLevel::PRACTICE)],
            ['is_memo_enabled' => ['attributeType' => AttributeType::BOOLEAN, 'overrideLevel' => OverrideLevel::PRACTICE]],
        );
    }

    public function testDefinitionsToInsertAbortsWhenOverrideWouldChange(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is_memo_enabled already exists as type=1 override=2; refusing type=1 override=3');

        CsiqFlagCatalog::definitionsToInsert(
            [$this->definition('is_memo_enabled', AttributeType::BOOLEAN, OverrideLevel::LOCATION_GROUP)],
            ['is_memo_enabled' => ['attributeType' => AttributeType::BOOLEAN, 'overrideLevel' => OverrideLevel::PRACTICE]],
        );
    }

    public function testDefinitionsToInsertAbortsOnConflictingBatchRows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conflicting type/override in this batch');

        CsiqFlagCatalog::definitionsToInsert(
            [
                $this->definition('is_memo_enabled', AttributeType::BOOLEAN, OverrideLevel::PRACTICE),
                $this->definition('is_memo_enabled', AttributeType::BOOLEAN, OverrideLevel::LOCATION_GROUP),
            ],
            [],
        );
    }

    public function testWarmupAcceptsHttp200(): void
    {
        $this->expectNotToPerformAssertions();
        CsiqFlagCatalog::assertWarmupSucceeded('http://127.0.0.1/warmup', 'ok', ['HTTP/1.1 200 OK']);
    }

    public function testWarmupAcceptsHttp204(): void
    {
        $this->expectNotToPerformAssertions();
        CsiqFlagCatalog::assertWarmupSucceeded('http://127.0.0.1/api/v1/warmup', '', ['HTTP/1.1 204 No Content']);
    }

    public function testWarmupRejectsAFailedRequest(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Warmup request failed: http://127.0.0.1/warmup');

        CsiqFlagCatalog::assertWarmupSucceeded('http://127.0.0.1/warmup', false, null);
    }

    public function testWarmupRejectsANonSuccessStatus(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Warmup expected HTTP 200 or 204 from http://127.0.0.1/warmup, got HTTP/1.1 500 Internal Server Error');

        CsiqFlagCatalog::assertWarmupSucceeded(
            'http://127.0.0.1/warmup',
            'nope',
            ['HTTP/1.1 500 Internal Server Error'],
        );
    }
}
