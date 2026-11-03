<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Unit;

use Carestack\Configuration\RpcConfigurationCondition;
use Carestack\Configuration\RpcConfigurationDetailsList;
use Carestack\Configuration\RpcDeleteConfigurationRequest;
use Carestack\Configuration\RpcMultiNamedConfiguration;
use Carestack\Configuration\RpcMultiPriorityNamedConfiguration;
use Carestack\Configuration\RpcNamedConfiguration;
use Carestack\Configuration\RpcToggleConfigurationSet;
use Carestack\Configuration\RpcToggleValue;
use Carestack\Configuration\RpcUpsertConfigurationRequest;
use Csiq\Configuration\AttributeType;
use Csiq\Configuration\ConfigDefinitionScope;
use Csiq\Configuration\OverrideLevel;
use Csiq\Configuration\Tests\Support\RpcMocks;
use Google\Protobuf\GPBEmpty;
use PHPUnit\Framework\TestCase;

final class ConfigurationClientRequestTest extends TestCase
{
    use RpcMocks;

    private mixed $sent = null;

    private function capture(string $method, mixed $response): void
    {
        $this->rpc->expects(self::once())->method($method)->willReturnCallback(
            function ($request) use ($response) {
                $this->sent = $request;

                return $this->unaryCall($response);
            },
        );
    }

    private static function toggle(): RpcToggleValue
    {
        return new RpcToggleValue();
    }

    public function testNamedRequestCarriesNameAccountAndServerScopeByDefault(): void
    {
        $client = $this->client();
        $this->capture('GetFeatureToggleValue', self::toggle());

        $client->isFeatureEnabled('is_memo_enabled', 10018);

        self::assertInstanceOf(RpcNamedConfiguration::class, $this->sent);
        self::assertSame('is_memo_enabled', $this->sent->getName());
        $condition = $this->sent->getCondition();
        self::assertSame(10018, $condition->getAccountIdUnwrapped());
        self::assertSame(ConfigDefinitionScope::SERVER, $condition->getConfigDefinitionScope());
    }

    public function testOptionalIdsAreLeftOffWhenNotPassed(): void
    {
        $client = $this->client();
        $this->capture('GetFeatureToggleValue', self::toggle());

        $client->isFeatureEnabled('flag', 1);

        $condition = $this->sent->getCondition();
        self::assertFalse($condition->hasUserId());
        self::assertFalse($condition->hasLocationId());
        self::assertFalse($condition->hasLocationGroupId());
        self::assertFalse($condition->hasAttributeArea());
    }

    public function testOptionalIdsAndScopeAreSentWhenPassed(): void
    {
        $client = $this->client();
        $this->capture('GetFeatureToggleValue', self::toggle());

        $client->isFeatureEnabled(
            'flag',
            1,
            userId: 2,
            locationId: 3,
            locationGroupId: 4,
            attributeArea: 5,
            configDefinitionScope: ConfigDefinitionScope::CLIENT,
        );

        $condition = $this->sent->getCondition();
        self::assertSame(2, $condition->getUserIdUnwrapped());
        self::assertSame(3, $condition->getLocationIdUnwrapped());
        self::assertSame(4, $condition->getLocationGroupIdUnwrapped());
        self::assertSame(5, $condition->getAttributeAreaUnwrapped());
        self::assertSame(ConfigDefinitionScope::CLIENT, $condition->getConfigDefinitionScope());
    }

    public function testDevToggleUsesItsOwnRpc(): void
    {
        $client = $this->client();
        $this->capture('GetDevToggleValue', self::toggle());
        $this->rpc->expects(self::never())->method('GetFeatureToggleValue');

        $client->isDevToggleEnabled('dev_flag', 1);

        self::assertSame('dev_flag', $this->sent->getName());
    }

    public function testGetAllWithoutNamesAsksForEverything(): void
    {
        $client = $this->client();
        $this->capture('GetAllTogglesAndConfigurations', new RpcToggleConfigurationSet());
        $this->rpc->expects(self::never())->method('GetAllTogglesAndConfigurationsByNames');

        $client->getAll(10018, userId: 7);

        self::assertInstanceOf(RpcConfigurationCondition::class, $this->sent);
        self::assertSame(10018, $this->sent->getAccountIdUnwrapped());
        self::assertSame(7, $this->sent->getUserIdUnwrapped());
    }

    public function testGetAllWithNamesAsksOnlyForThose(): void
    {
        $client = $this->client();
        $this->capture('GetAllTogglesAndConfigurationsByNames', new RpcToggleConfigurationSet());
        $this->rpc->expects(self::never())->method('GetAllTogglesAndConfigurations');

        $client->getAll(10018, ['is_memo_enabled', 'dni_regex']);

        self::assertInstanceOf(RpcMultiNamedConfiguration::class, $this->sent);
        self::assertSame(['is_memo_enabled', 'dni_regex'], iterator_to_array($this->sent->getNames()));
        self::assertSame(10018, $this->sent->getCondition()->getAccountIdUnwrapped());
    }

    public function testGetAllAccountConfigurationsSendsThePriorities(): void
    {
        $client = $this->client();
        $this->capture('GetAllAccountConfigurationByNamesAndPriorities', new RpcConfigurationDetailsList());

        $client->getAllAccountConfigurations('flag', 10018, [OverrideLevel::PRACTICE, OverrideLevel::USER], userId: 7);

        self::assertInstanceOf(RpcMultiPriorityNamedConfiguration::class, $this->sent);
        self::assertSame('flag', $this->sent->getName());
        self::assertSame([OverrideLevel::PRACTICE, OverrideLevel::USER], iterator_to_array($this->sent->getPriorities()));
        self::assertSame(7, $this->sent->getCondition()->getUserIdUnwrapped());
    }

    public function testUpsertSendsEditorTypedValueAndCondition(): void
    {
        $client = $this->client();
        $this->capture('UpsertConfiguration', new GPBEmpty());

        $client->upsert('is_memo_enabled', 10018, true, 99, userId: 7);

        self::assertInstanceOf(RpcUpsertConfigurationRequest::class, $this->sent);
        self::assertSame(99, $this->sent->getEditedByUserId());
        self::assertSame(AttributeType::BOOLEAN, $this->sent->getTypeValuePair()->getType());
        self::assertSame('true', $this->sent->getTypeValuePair()->getValueUnwrapped());
        self::assertSame('is_memo_enabled', $this->sent->getCondition()->getName());
        self::assertSame(10018, $this->sent->getCondition()->getCondition()->getAccountIdUnwrapped());
        self::assertSame(7, $this->sent->getCondition()->getCondition()->getUserIdUnwrapped());
    }

    public function testDeleteSendsDeleterAndCondition(): void
    {
        $client = $this->client();
        $this->capture('DeleteConfiguration', new GPBEmpty());

        $client->delete('is_memo_enabled', 10018, 99, locationGroupId: 4);

        self::assertInstanceOf(RpcDeleteConfigurationRequest::class, $this->sent);
        self::assertSame(99, $this->sent->getDeletedByUserId());
        self::assertSame('is_memo_enabled', $this->sent->getCondition()->getName());
        self::assertSame(4, $this->sent->getCondition()->getCondition()->getLocationGroupIdUnwrapped());
    }
}
