<?php

declare(strict_types=1);

namespace Csiq\Configuration;

use Carestack\Configuration\RpcConfigurationCondition;
use Carestack\Configuration\RpcConfigurationDetails;
use Carestack\Configuration\RpcConfigurationDetailsList;
use Carestack\Configuration\RpcConfigurationDetailsNullable;
use Carestack\Configuration\RpcDeleteConfigurationRequest;
use Carestack\Configuration\RpcMultiNamedConfiguration;
use Carestack\Configuration\RpcMultiPriorityNamedConfiguration;
use Carestack\Configuration\RpcNamedConfiguration;
use Carestack\Configuration\RpcToggleConfigurationSet;
use Carestack\Configuration\RpcToggleValue;
use Carestack\Configuration\RpcUpsertConfigurationRequest;
use Google\Protobuf\GPBEmpty;
use Grpc\BaseStub;
use Grpc\ChannelCredentials;
use Grpc\UnaryCall;

class RpcConfigurationServiceClient extends BaseStub
{
    public function __construct(ConfigurationClientConfig $config)
    {
        parent::__construct($config->host, [
            'credentials' => $config->insecure
                ? ChannelCredentials::createInsecure()
                : ChannelCredentials::createSsl(),
            // -1 is unlimited (grpc-core). Same as CareStack MaxReceiveMessageSize = null.
            'grpc.max_receive_message_length' => -1,
            'grpc.keepalive_time_ms' => $config->keepaliveTimeMs,
            'grpc.keepalive_timeout_ms' => $config->keepaliveTimeoutMs,
            'grpc.keepalive_permit_without_calls' => 1,
        ]);
    }

    public function GetFeatureToggleValue(RpcNamedConfiguration $argument, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest(
            '/carestack.configuration.RpcConfigurationService/GetFeatureToggleValue',
            $argument,
            [RpcToggleValue::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function GetDevToggleValue(RpcNamedConfiguration $argument, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest(
            '/carestack.configuration.RpcConfigurationService/GetDevToggleValue',
            $argument,
            [RpcToggleValue::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function GetConfigurationDetails(RpcNamedConfiguration $argument, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest(
            '/carestack.configuration.RpcConfigurationService/GetConfigurationDetails',
            $argument,
            [RpcConfigurationDetails::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function TryGetConfigurationDetails(RpcNamedConfiguration $argument, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest(
            '/carestack.configuration.RpcConfigurationService/TryGetConfigurationDetails',
            $argument,
            [RpcConfigurationDetailsNullable::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function GetAllAccountConfigurationByNamesAndPriorities(RpcMultiPriorityNamedConfiguration $argument, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest(
            '/carestack.configuration.RpcConfigurationService/GetAllAccountConfigurationByNamesAndPriorities',
            $argument,
            [RpcConfigurationDetailsList::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function GetAllTogglesAndConfigurations(RpcConfigurationCondition $argument, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest(
            '/carestack.configuration.RpcConfigurationService/GetAllTogglesAndConfigurations',
            $argument,
            [RpcToggleConfigurationSet::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function GetAllTogglesAndConfigurationsByNames(RpcMultiNamedConfiguration $argument, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest(
            '/carestack.configuration.RpcConfigurationService/GetAllTogglesAndConfigurationsByNames',
            $argument,
            [RpcToggleConfigurationSet::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function UpsertConfiguration(RpcUpsertConfigurationRequest $argument, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest(
            '/carestack.configuration.RpcConfigurationService/UpsertConfiguration',
            $argument,
            [GPBEmpty::class, 'decode'],
            $metadata,
            $options,
        );
    }

    public function DeleteConfiguration(RpcDeleteConfigurationRequest $argument, array $metadata = [], array $options = []): UnaryCall
    {
        return $this->_simpleRequest(
            '/carestack.configuration.RpcConfigurationService/DeleteConfiguration',
            $argument,
            [GPBEmpty::class, 'decode'],
            $metadata,
            $options,
        );
    }
}
