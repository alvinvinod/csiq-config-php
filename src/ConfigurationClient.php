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
use Carestack\Configuration\RpcTypeValuePair;
use Carestack\Configuration\RpcUpsertConfigurationRequest;
use Grpc\UnaryCall;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use const Grpc\STATUS_OK;

/**
 * IConfigurationClient over the generated gRPC stub.
 *
 * Build one per process through ConfigurationClientFactory or the app container.
 * Method contracts are on IConfigurationClient.
 */
final class ConfigurationClient implements IConfigurationClient
{
    public function __construct(
        private RpcConfigurationServiceClient $rpc,
        private ConfigurationClientConfig $config,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /** {@inheritdoc} */
    public function isFeatureEnabled(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): bool {
        $named = $this->named($name, $casTenantId, $userId, $locationId, $locationGroupId, $attributeArea, $configDefinitionScope);
        /** @var RpcToggleValue $response */
        $response = $this->call(fn (array $metadata, array $options) => $this->rpc->GetFeatureToggleValue($named, $metadata, $options));

        return $response->getValue();
    }

    /** {@inheritdoc} */
    public function isDevToggleEnabled(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): bool {
        $named = $this->named($name, $casTenantId, $userId, $locationId, $locationGroupId, $attributeArea, $configDefinitionScope);
        /** @var RpcToggleValue $response */
        $response = $this->call(fn (array $metadata, array $options) => $this->rpc->GetDevToggleValue($named, $metadata, $options));

        return $response->getValue();
    }

    /** {@inheritdoc} */
    public function tryGet(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): mixed {
        $named = $this->named($name, $casTenantId, $userId, $locationId, $locationGroupId, $attributeArea, $configDefinitionScope);
        /** @var RpcConfigurationDetailsNullable $response */
        $response = $this->call(fn (array $metadata, array $options) => $this->rpc->TryGetConfigurationDetails($named, $metadata, $options));
        if (!$response->hasConfigurationDetails()) {
            return null;
        }

        $pair = $response->getConfigurationDetails()?->getTypeValuePair();
        if ($pair === null) {
            return null;
        }

        return $this->decode($pair);
    }

    /** {@inheritdoc} */
    public function getConfigurationDetails(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): ConfigurationDetails {
        $named = $this->named($name, $casTenantId, $userId, $locationId, $locationGroupId, $attributeArea, $configDefinitionScope);
        /** @var RpcConfigurationDetails|null $response */
        $response = $this->call(fn (array $metadata, array $options) => $this->rpc->GetConfigurationDetails($named, $metadata, $options));
        if (!$response instanceof RpcConfigurationDetails) {
            throw new ConfigurationException(sprintf(
                'Entry not found for %s with provided condition (If this is expected - please use tryGet instead)',
                $name,
            ));
        }

        return $this->toDetails($response);
    }

    /** {@inheritdoc} */
    public function getAllAccountConfigurations(
        string $name,
        int $casTenantId,
        array $overrideLevels,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): array {
        $request = new RpcMultiPriorityNamedConfiguration();
        $request->setName($name);
        $request->setCondition($this->rpcCondition($casTenantId, $userId, $locationId, $locationGroupId, $attributeArea, $configDefinitionScope));
        $request->setPriorities($overrideLevels);

        /** @var RpcConfigurationDetailsList $response */
        $response = $this->call(fn (array $metadata, array $options) => $this->rpc->GetAllAccountConfigurationByNamesAndPriorities($request, $metadata, $options));

        $details = [];
        foreach ($response->getItems() as $item) {
            $details[] = $this->toDetails($item);
        }

        return $details;
    }

    /** {@inheritdoc} */
    public function getAll(
        int $casTenantId,
        array $names = [],
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): ToggleSet {
        $condition = $this->rpcCondition($casTenantId, $userId, $locationId, $locationGroupId, $attributeArea, $configDefinitionScope);
        if ($names === []) {
            /** @var RpcToggleConfigurationSet $response */
            $response = $this->call(fn (array $metadata, array $options) => $this->rpc->GetAllTogglesAndConfigurations($condition, $metadata, $options));
        } else {
            $request = new RpcMultiNamedConfiguration();
            $request->setNames($names);
            $request->setCondition($condition);
            /** @var RpcToggleConfigurationSet $response */
            $response = $this->call(fn (array $metadata, array $options) => $this->rpc->GetAllTogglesAndConfigurationsByNames($request, $metadata, $options));
        }

        $toggles = [];
        foreach ($response->getToggles() as $key => $value) {
            $toggles[(string) $key] = (bool) $value;
        }

        $configurations = [];
        foreach ($response->getConfigurations() as $key => $pair) {
            $configurations[(string) $key] = $this->decode($pair);
        }

        return new ToggleSet($toggles, $configurations);
    }

    /** {@inheritdoc} */
    public function upsert(
        string $name,
        int $casTenantId,
        mixed $value,
        int $editedByUserId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): void {
        [$type, $json] = $this->encode($value);
        $pair = new RpcTypeValuePair();
        $pair->setType($type);
        $pair->setValueUnwrapped($json);

        $request = new RpcUpsertConfigurationRequest();
        $request->setTypeValuePair($pair);
        $request->setEditedByUserId($editedByUserId);
        $request->setCondition($this->named($name, $casTenantId, $userId, $locationId, $locationGroupId, $attributeArea, $configDefinitionScope));

        $this->call(fn (array $metadata, array $options) => $this->rpc->UpsertConfiguration($request, $metadata, $options));
    }

    /** {@inheritdoc} */
    public function delete(
        string $name,
        int $casTenantId,
        int $deletedByUserId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): void {
        $request = new RpcDeleteConfigurationRequest();
        $request->setDeletedByUserId($deletedByUserId);
        $request->setCondition($this->named($name, $casTenantId, $userId, $locationId, $locationGroupId, $attributeArea, $configDefinitionScope));

        $this->call(fn (array $metadata, array $options) => $this->rpc->DeleteConfiguration($request, $metadata, $options));
    }

    private function named(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): RpcNamedConfiguration {
        $named = new RpcNamedConfiguration();
        $named->setName($name);
        $named->setCondition($this->rpcCondition($casTenantId, $userId, $locationId, $locationGroupId, $attributeArea, $configDefinitionScope));

        return $named;
    }

    private function rpcCondition(
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): RpcConfigurationCondition {
        $condition = new RpcConfigurationCondition();
        $condition->setAccountIdUnwrapped($casTenantId);
        $condition->setConfigDefinitionScope($configDefinitionScope);
        if ($userId !== null) {
            $condition->setUserIdUnwrapped($userId);
        }
        if ($locationId !== null) {
            $condition->setLocationIdUnwrapped($locationId);
        }
        if ($locationGroupId !== null) {
            $condition->setLocationGroupIdUnwrapped($locationGroupId);
        }
        if ($attributeArea !== null) {
            $condition->setAttributeAreaUnwrapped($attributeArea);
        }

        return $condition;
    }

    /** @return array{0: int, 1: string} */
    private function encode(mixed $value): array
    {
        return match (true) {
            is_bool($value) => [AttributeType::BOOLEAN, json_encode($value, JSON_THROW_ON_ERROR)],
            is_int($value) => [AttributeType::NUMBER, json_encode($value, JSON_THROW_ON_ERROR)],
            is_string($value) => [AttributeType::STRING, json_encode($value, JSON_THROW_ON_ERROR)],
            default => [AttributeType::JSON, json_encode($value, JSON_THROW_ON_ERROR)],
        };
    }

    private function toDetails(RpcConfigurationDetails $rpc): ConfigurationDetails
    {
        $pair = $rpc->getTypeValuePair();

        return new ConfigurationDetails(
            name: $rpc->getName(),
            priority: $rpc->getPriority(),
            type: $rpc->getType(),
            value: $pair === null ? null : $this->decode($pair),
            accountId: $rpc->getAccountIdUnwrapped(),
            userId: $rpc->getUserIdUnwrapped(),
            locationGroupId: $rpc->getLocationGroupIdUnwrapped(),
            locationId: $rpc->getLocationIdUnwrapped(),
        );
    }

    private function decode(RpcTypeValuePair $pair): mixed
    {
        $json = $pair->getValueUnwrapped();
        if ($json === null || $json === '') {
            return null;
        }

        return match ($pair->getType()) {
            AttributeType::BOOLEAN, AttributeType::BIT => (bool) json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            AttributeType::NUMBER => (int) json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            AttributeType::STRING => (string) json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            AttributeType::DATE => (string) json_decode($json, false, 512, JSON_THROW_ON_ERROR),
            default => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
        };
    }

    /**
     * Runs one unary RPC under the retry policy. timeoutMs is a deadline for the whole
     * call: each attempt gets the time that is left, and no backoff starts that would
     * end past it.
     *
     * @param callable(array<string, list<string>>, array<string, mixed>): UnaryCall $start
     */
    private function call(callable $start): mixed
    {
        $retry = $this->config->retryPolicy;
        $startedNs = hrtime(true);
        $deadlineNs = $startedNs + $this->config->timeoutMs * 1_000_000;
        $backoffMs = $retry->initialBackoffMs;
        $metadata = [];

        for ($attempt = 1; ; $attempt++) {
            $remainingUs = intdiv($deadlineNs - hrtime(true), 1000);
            [$response, $status] = $start($metadata, ['timeout' => max($remainingUs, 1)])->wait();
            $code = (int) ($status->code ?? STATUS_OK);
            if ($code === STATUS_OK) {
                if ($attempt > 1) {
                    $this->logRpc(LogLevel::WARNING, $attempt, $retry->maxAttempts, $code, '', $startedNs, false);
                }

                return $response;
            }

            $details = (string) ($status->details ?? '');
            $delayUs = self::jitterUs($backoffMs);
            $giveUp = $attempt >= $retry->maxAttempts
                || !$retry->isRetryable($code)
                || hrtime(true) + $delayUs * 1000 >= $deadlineNs;
            $this->logRpc(
                $giveUp ? LogLevel::ERROR : LogLevel::WARNING,
                $attempt,
                $retry->maxAttempts,
                $code,
                $details,
                $startedNs,
                !$giveUp,
            );
            if ($giveUp) {
                throw new ConfigurationException(
                    sprintf('gRPC %d: %s (attempt %d of %d)', $code, $details, $attempt, $retry->maxAttempts),
                    $code,
                );
            }

            usleep($delayUs);
            $backoffMs = min((int) ($backoffMs * $retry->backoffMultiplier), $retry->maxBackoffMs);
        }
    }

    /** Equal jitter: half the backoff plus a random remainder, in microseconds. */
    private static function jitterUs(int $backoffMs): int
    {
        $delayUs = $backoffMs * 1000;
        if ($delayUs < 1) {
            return 0;
        }

        $half = intdiv($delayUs, 2);

        return $half + random_int(0, $delayUs - $half);
    }

    private function logRpc(
        string $level,
        int $attempt,
        int $maxAttempts,
        int $code,
        string $details,
        int $startedNs,
        bool $willRetry,
    ): void {
        if ($this->logger === null) {
            return;
        }

        $elapsedMs = (hrtime(true) - $startedNs) / 1_000_000;
        $this->logger->log(
            $level,
            sprintf('gRPC %d: %s (attempt %d of %d)', $code, $details, $attempt, $maxAttempts),
            [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'code' => $code,
                'elapsed_ms' => round($elapsedMs, 2),
                'will_retry' => $willRetry,
            ],
        );
    }
}
