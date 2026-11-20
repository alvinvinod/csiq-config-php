<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Unit;

use Carestack\Configuration\RpcConfigurationDetails;
use Carestack\Configuration\RpcConfigurationDetailsList;
use Carestack\Configuration\RpcConfigurationDetailsNullable;
use Carestack\Configuration\RpcToggleConfigurationSet;
use Carestack\Configuration\RpcToggleValue;
use Csiq\Configuration\AttributeType;
use Csiq\Configuration\ConfigurationDetails;
use Csiq\Configuration\ConfigurationException;
use Csiq\Configuration\OverrideLevel;
use Csiq\Configuration\Tests\Support\RpcMocks;
use PHPUnit\Framework\TestCase;

use const Grpc\STATUS_INVALID_ARGUMENT;

final class ConfigurationClientResponseTest extends TestCase
{
    use RpcMocks;

    private static function details(string $name, int $priority, int $type, ?string $json): RpcConfigurationDetails
    {
        $details = new RpcConfigurationDetails();
        $details->setName($name);
        $details->setPriority($priority);
        $details->setType($type);
        if ($json !== null) {
            $details->setTypeValuePair(self::pair($type, $json));
        }

        return $details;
    }

    public function testToggleReadsReturnTheBoolean(): void
    {
        $client = $this->client();
        $on = new RpcToggleValue();
        $on->setValue(true);
        $this->rpc->method('GetFeatureToggleValue')->willReturn($this->unaryCall($on));
        $this->rpc->method('GetDevToggleValue')->willReturn($this->unaryCall(new RpcToggleValue()));

        self::assertTrue($client->isFeatureEnabled('flag', 1));
        self::assertFalse($client->isDevToggleEnabled('flag', 1));
    }

    public function testTryGetReturnsNullWhenNothingMatched(): void
    {
        $client = $this->client();
        $this->rpc->method('TryGetConfigurationDetails')->willReturn($this->unaryCall(new RpcConfigurationDetailsNullable()));

        self::assertNull($client->tryGet('missing', 1));
    }

    public function testTryGetReturnsNullWhenDetailsHaveNoValue(): void
    {
        $client = $this->client();
        $response = new RpcConfigurationDetailsNullable();
        $response->setConfigurationDetails(self::details('flag', OverrideLevel::PRACTICE, AttributeType::STRING, null));
        $this->rpc->method('TryGetConfigurationDetails')->willReturn($this->unaryCall($response));

        self::assertNull($client->tryGet('flag', 1));
    }

    public function testTryGetReturnsTheDecodedValue(): void
    {
        $client = $this->client();
        $response = new RpcConfigurationDetailsNullable();
        $response->setConfigurationDetails(self::details('dni_regex', OverrideLevel::PRACTICE, AttributeType::STRING, '"^\\\\+1"'));
        $this->rpc->method('TryGetConfigurationDetails')->willReturn($this->unaryCall($response));

        self::assertSame('^\+1', $client->tryGet('dni_regex', 1));
    }

    public function testGetConfigurationDetailsMapsEveryField(): void
    {
        $client = $this->client();
        $rpc = self::details('is_audio_download_enabled', OverrideLevel::USER, AttributeType::BOOLEAN, 'true');
        $rpc->setAccountIdUnwrapped(10018);
        $rpc->setUserIdUnwrapped(7);
        $rpc->setLocationGroupIdUnwrapped(4);
        $rpc->setLocationIdUnwrapped(3);
        $this->rpc->method('GetConfigurationDetails')->willReturn($this->unaryCall($rpc));

        self::assertEquals(
            new ConfigurationDetails(
                name: 'is_audio_download_enabled',
                priority: OverrideLevel::USER,
                type: AttributeType::BOOLEAN,
                value: true,
                accountId: 10018,
                userId: 7,
                locationGroupId: 4,
                locationId: 3,
            ),
            $client->getConfigurationDetails('is_audio_download_enabled', 10018, userId: 7),
        );
    }

    public function testGetConfigurationDetailsLeavesUnsetIdsNull(): void
    {
        $client = $this->client();
        $this->rpc->method('GetConfigurationDetails')->willReturn(
            $this->unaryCall(self::details('flag', OverrideLevel::VOICESTACK, AttributeType::BOOLEAN, 'false')),
        );

        $details = $client->getConfigurationDetails('flag', 1);

        self::assertFalse($details->value);
        self::assertNull($details->accountId);
        self::assertNull($details->userId);
        self::assertNull($details->locationGroupId);
        self::assertNull($details->locationId);
    }

    public function testGetAllMapsTogglesAndDecodesConfigurations(): void
    {
        $client = $this->client();
        $set = new RpcToggleConfigurationSet();
        $set->setToggles(['is_memo_enabled' => true, 'is_fax_enabled' => false]);
        $set->setConfigurations([
            'dni_regex' => self::pair(AttributeType::STRING, '"^\\\\+1"'),
            'transcription_languages' => self::pair(AttributeType::JSON, '["en-US"]'),
            'max_agents' => self::pair(AttributeType::NUMBER, '25'),
        ]);
        $this->rpc->method('GetAllTogglesAndConfigurations')->willReturn($this->unaryCall($set));

        $result = $client->getAll(10018);

        self::assertSame(['is_memo_enabled' => true, 'is_fax_enabled' => false], $result->toggles);
        self::assertSame(
            ['dni_regex' => '^\+1', 'transcription_languages' => ['en-US'], 'max_agents' => 25],
            $result->configurations,
        );
    }

    public function testGetAllReturnsEmptyMapsForAnEmptySet(): void
    {
        $client = $this->client();
        $this->rpc->method('GetAllTogglesAndConfigurations')->willReturn($this->unaryCall(new RpcToggleConfigurationSet()));

        $result = $client->getAll(10018);

        self::assertSame([], $result->toggles);
        self::assertSame([], $result->configurations);
    }

    public function testGetAllAccountConfigurationsMapsEachLayer(): void
    {
        $client = $this->client();
        $practice = self::details('flag', OverrideLevel::PRACTICE, AttributeType::BOOLEAN, 'false');
        $practice->setAccountIdUnwrapped(10018);
        $user = self::details('flag', OverrideLevel::USER, AttributeType::BOOLEAN, 'true');
        $user->setAccountIdUnwrapped(10018);
        $user->setUserIdUnwrapped(7);
        $list = new RpcConfigurationDetailsList();
        $list->setItems([$practice, $user]);
        $this->rpc->method('GetAllAccountConfigurationByNamesAndPriorities')->willReturn($this->unaryCall($list));

        $rows = $client->getAllAccountConfigurations('flag', 10018, [OverrideLevel::PRACTICE, OverrideLevel::USER], userId: 7);

        self::assertCount(2, $rows);
        self::assertSame([OverrideLevel::PRACTICE, false, null], [$rows[0]->priority, $rows[0]->value, $rows[0]->userId]);
        self::assertSame([OverrideLevel::USER, true, 7], [$rows[1]->priority, $rows[1]->value, $rows[1]->userId]);
    }

    public function testGetAllAccountConfigurationsReturnsAnEmptyList(): void
    {
        $client = $this->client();
        $this->rpc->method('GetAllAccountConfigurationByNamesAndPriorities')->willReturn($this->unaryCall(new RpcConfigurationDetailsList()));

        self::assertSame([], $client->getAllAccountConfigurations('flag', 1, [OverrideLevel::PRACTICE]));
    }

    public function testErrorStatusBecomesConfigurationExceptionWithCodeAndDetails(): void
    {
        $client = $this->client();
        $this->rpc->method('GetFeatureToggleValue')->willReturn(
            $this->unaryCall(null, STATUS_INVALID_ARGUMENT, 'Name must not be empty'),
        );

        try {
            $client->isFeatureEnabled('', 1);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertSame(STATUS_INVALID_ARGUMENT, $e->getCode());
            self::assertSame('gRPC 3: Name must not be empty (attempt 1 of 5)', $e->getMessage());
        }
    }
}
