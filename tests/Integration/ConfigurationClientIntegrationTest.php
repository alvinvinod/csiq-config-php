<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Integration;

use Csiq\Configuration\ConfigurationClientConfig;
use Csiq\Configuration\ConfigurationClientFactory;
use Csiq\Configuration\ConfigurationException;
use Csiq\Configuration\IConfigurationClient;
use Csiq\Configuration\OverrideLevel;
use Csiq\Configuration\RetryPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use const Grpc\STATUS_UNKNOWN;

/**
 * Live configuration service. Seed the names in tests/fixtures/integration-seed.json
 * (SQL / SyncTool), then:
 *
 *   CONFIG_SERVICE_INTEGRATION=1 vendor/bin/phpunit --testsuite integration
 */
#[Group('integration')]
final class ConfigurationClientIntegrationTest extends TestCase
{
    private IConfigurationClient $client;

    /** @var array<string, mixed> */
    private array $fixture;

    private int $casTenantId;

    private int $userId;

    private int $locationGroupId;

    protected function setUp(): void
    {
        if (getenv('CONFIG_SERVICE_INTEGRATION') !== '1') {
            self::markTestSkipped('Set CONFIG_SERVICE_INTEGRATION=1 after seeding the service');
        }

        $this->fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/integration-seed.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->casTenantId = (int) $this->fixture['accountId'];
        $this->userId = (int) $this->fixture['userId'];
        $this->locationGroupId = (int) $this->fixture['locationGroupId'];
        $this->client = ConfigurationClientFactory::create();
    }

    public function testPracticeToggleIsOn(): void
    {
        self::assertTrue($this->client->isFeatureEnabled('phpunit_practice_toggle', $this->casTenantId));
    }

    public function testUserToggleOverridesPracticeWhenUserIdIsPassed(): void
    {
        self::assertFalse($this->client->isFeatureEnabled('phpunit_user_toggle', $this->casTenantId));
        self::assertTrue($this->client->isFeatureEnabled('phpunit_user_toggle', $this->casTenantId, userId: $this->userId));
    }

    public function testDefinedToggleWithNoStoredValueReadsAsFalse(): void
    {
        // GetConfigurationAsync synthesizes false for a toggle definition that has no row.
        // getAllAccountConfigurations does not; it only returns stored rows.
        self::assertFalse($this->client->isFeatureEnabled('phpunit_unset_toggle', $this->casTenantId));
        self::assertFalse($this->client->tryGet('phpunit_unset_toggle', $this->casTenantId));
        self::assertSame(
            [],
            $this->client->getAllAccountConfigurations('phpunit_unset_toggle', $this->casTenantId, [OverrideLevel::PRACTICE]),
        );
    }

    public function testMissingDefinitionFailsSoftOnReadsExceptGetConfigurationDetails(): void
    {
        $name = 'phpunit_missing_key_zzz';

        self::assertFalse($this->client->isFeatureEnabled($name, $this->casTenantId));
        self::assertNull($this->client->tryGet($name, $this->casTenantId));

        $set = $this->client->getAll($this->casTenantId, [$name]);
        self::assertArrayNotHasKey($name, $set->toggles);
        self::assertArrayNotHasKey($name, $set->configurations);
    }

    /**
     * The server maps GetConfigurationDetails-on-missing to FluentValidation.ValidationException,
     * then ExceptionInterceptor maps that to UNKNOWN. UNKNOWN is retryable, so the client
     * retries a deterministic miss. Flip this test when the interceptor maps it to NOT_FOUND.
     */
    public function testPinnedGetConfigurationDetailsOnAMissingKeyRetriesAsUnknown(): void
    {
        $client = ConfigurationClientFactory::create(new ConfigurationClientConfig(
            getenv('CONFIG_SERVICE_URI') ?: '127.0.0.1:8081',
            true,
            2000,
            new RetryPolicy(5, 200, 400, 2.0),
        ));

        try {
            $client->getConfigurationDetails('phpunit_missing_key_zzz', $this->casTenantId);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertSame(STATUS_UNKNOWN, $e->getCode());
            self::assertMatchesRegularExpression('/attempt [2-5] of 5/', $e->getMessage());
        }
    }

    public function testDevToggle(): void
    {
        self::assertTrue($this->client->isDevToggleEnabled('phpunit_dev_toggle', $this->casTenantId));
    }

    public function testTypedConfigsComeBackDecoded(): void
    {
        self::assertSame('hello', $this->client->tryGet('phpunit_string_config', $this->casTenantId));
        self::assertSame(42, $this->client->tryGet('phpunit_number_config', $this->casTenantId));
        self::assertSame(
            ['languages' => ['en-US', 'es-US']],
            $this->client->tryGet('phpunit_json_config', $this->casTenantId),
        );
    }

    public function testLocationGroupValueWinsWhenLocationGroupIdIsPassed(): void
    {
        self::assertSame('practice-level', $this->client->tryGet('phpunit_location_group_config', $this->casTenantId));
        self::assertSame(
            'group-level',
            $this->client->tryGet('phpunit_location_group_config', $this->casTenantId, locationGroupId: $this->locationGroupId),
        );
    }

    public function testGetAllByNamesReturnsTheSeededSet(): void
    {
        $set = $this->client->getAll($this->casTenantId, [
            'phpunit_practice_toggle',
            'phpunit_string_config',
            'phpunit_number_config',
        ], userId: $this->userId);

        self::assertTrue($set->toggles['phpunit_practice_toggle']);
        self::assertSame('hello', $set->configurations['phpunit_string_config']);
        self::assertSame(42, $set->configurations['phpunit_number_config']);
    }

    public function testGetAllAccountConfigurationsReturnsPracticeAndUserLayers(): void
    {
        $rows = $this->client->getAllAccountConfigurations(
            'phpunit_user_toggle',
            $this->casTenantId,
            [OverrideLevel::PRACTICE, OverrideLevel::USER],
            userId: $this->userId,
        );

        $byPriority = [];
        foreach ($rows as $row) {
            $byPriority[$row->priority] = $row->value;
        }
        self::assertSame(false, $byPriority[OverrideLevel::PRACTICE] ?? null);
        self::assertSame(true, $byPriority[OverrideLevel::USER] ?? null);
    }

    public function testUpsertThenReadThenDeleteScratchToggle(): void
    {
        $name = 'phpunit_scratch_toggle';
        $editedBy = (int) $this->fixture['editedBy'];

        $this->client->upsert($name, $this->casTenantId, true, $editedBy);
        self::assertTrue($this->client->isFeatureEnabled($name, $this->casTenantId));

        $this->client->upsert($name, $this->casTenantId, false, $editedBy);
        self::assertFalse($this->client->isFeatureEnabled($name, $this->casTenantId));

        $this->client->delete($name, $this->casTenantId, $editedBy);
        // The definition remains, so the missing row is the toggle default again.
        self::assertFalse($this->client->isFeatureEnabled($name, $this->casTenantId));
        self::assertFalse($this->client->tryGet($name, $this->casTenantId));
    }

    /**
     * Reading a string config through isFeatureEnabled does not come back as INVALID_ARGUMENT
     * on this server. The status is UNKNOWN, and UNKNOWN is in the retry list, so the client
     * spends the full attempt budget. Flip this if the server maps the type mismatch to
     * INVALID_ARGUMENT.
     */
    public function testPinnedFeatureToggleReadOnAConfigRetriesAsUnknown(): void
    {
        $client = ConfigurationClientFactory::create(new ConfigurationClientConfig(
            getenv('CONFIG_SERVICE_URI') ?: '127.0.0.1:8081',
            true,
            2000,
            new RetryPolicy(5, 200, 400, 2.0),
        ));

        try {
            $client->isFeatureEnabled('phpunit_string_config', $this->casTenantId);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertSame(STATUS_UNKNOWN, $e->getCode());
            self::assertMatchesRegularExpression('/attempt [2-5] of 5/', $e->getMessage());
        }
    }
}
