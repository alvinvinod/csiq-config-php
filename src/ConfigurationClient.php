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
use Error;
use Grpc\UnaryCall;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use TypeError;

use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_OK;
use const Grpc\STATUS_UNKNOWN;

/**
 * IConfigurationClient over the generated gRPC stub.
 *
 * Build one per process through ConfigurationClientFactory or the app container.
 * Method contracts are on IConfigurationClient.
 */
final class ConfigurationClient implements IConfigurationClient
{
    private const INT32_MIN = -2147483648;
    private const INT32_MAX = 2147483647;

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

        return $this->decode($pair, $name);
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
        /** @var RpcConfigurationDetails $response */
        $response = $this->call(fn (array $metadata, array $options) => $this->rpc->GetConfigurationDetails($named, $metadata, $options));

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
            $configurations[(string) $key] = $this->decode($pair, (string) $key);
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
        [$type, $json] = $this->encode($value, $name);
        $pair = new RpcTypeValuePair();
        $pair->setType($type);
        $pair->setValueUnwrapped($json);

        $request = new RpcUpsertConfigurationRequest();
        $request->setTypeValuePair($pair);
        $request->setEditedByUserId(self::int32($editedByUserId, 'editedByUserId'));
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
        $request->setDeletedByUserId(self::int32($deletedByUserId, 'deletedByUserId'));
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
        $condition->setAccountIdUnwrapped(self::int32($casTenantId, 'casTenantId'));
        $condition->setConfigDefinitionScope($configDefinitionScope);
        if ($userId !== null) {
            $condition->setUserIdUnwrapped(self::int32($userId, 'userId'));
        }
        if ($locationId !== null) {
            $condition->setLocationIdUnwrapped(self::int32($locationId, 'locationId'));
        }
        if ($locationGroupId !== null) {
            $condition->setLocationGroupIdUnwrapped(self::int32($locationGroupId, 'locationGroupId'));
        }
        if ($attributeArea !== null) {
            $condition->setAttributeAreaUnwrapped(self::int32($attributeArea, 'attributeArea'));
        }

        return $condition;
    }

    /** @return array{0: int, 1: string} */
    private function encode(mixed $value, string $name): array
    {
        try {
            return match (true) {
                is_bool($value) => [AttributeType::BOOLEAN, json_encode($value, JSON_THROW_ON_ERROR)],
                is_int($value) => [AttributeType::NUMBER, json_encode($value, JSON_THROW_ON_ERROR)],
                is_string($value) => [AttributeType::STRING, json_encode($value, JSON_THROW_ON_ERROR)],
                default => [AttributeType::JSON, json_encode($value, JSON_THROW_ON_ERROR)],
            };
        } catch (JsonException|TypeError|Error $e) {
            throw new ConfigurationException(sprintf('cannot encode value for %s', $name), 0, $e);
        }
    }

    private function toDetails(RpcConfigurationDetails $rpc): ConfigurationDetails
    {
        $pair = $rpc->getTypeValuePair();
        $name = $rpc->getName();

        return new ConfigurationDetails(
            name: $name,
            priority: $rpc->getPriority(),
            type: $rpc->getType(),
            value: $pair === null ? null : $this->decode($pair, $name),
            accountId: $rpc->getAccountIdUnwrapped(),
            userId: $rpc->getUserIdUnwrapped(),
            locationGroupId: $rpc->getLocationGroupIdUnwrapped(),
            locationId: $rpc->getLocationIdUnwrapped(),
        );
    }

    private function decode(RpcTypeValuePair $pair, string $name): mixed
    {
        $json = $pair->getValueUnwrapped();
        if ($json === null || $json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return match ($pair->getType()) {
                AttributeType::BOOLEAN, AttributeType::BIT => self::expectBool($decoded, $name),
                AttributeType::NUMBER => self::expectInt32Number($decoded, $name),
                AttributeType::STRING, AttributeType::DATE => self::expectString($decoded, $name),
                default => $decoded,
            };
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (JsonException|TypeError|Error $e) {
            throw new ConfigurationException(sprintf('bad value for %s', $name), 0, $e);
        }
    }

    private static function expectBool(mixed $decoded, string $name): bool
    {
        if (!is_bool($decoded)) {
            throw new ConfigurationException(sprintf('bad value for %s', $name));
        }

        return $decoded;
    }

    private static function expectInt32Number(mixed $decoded, string $name): int
    {
        if (!is_int($decoded) || $decoded < self::INT32_MIN || $decoded > self::INT32_MAX) {
            throw new ConfigurationException(sprintf('bad value for %s', $name));
        }

        return $decoded;
    }

    private static function expectString(mixed $decoded, string $name): string
    {
        if (!is_string($decoded)) {
            throw new ConfigurationException(sprintf('bad value for %s', $name));
        }

        return $decoded;
    }

    private static function int32(int $id, string $field): int
    {
        if ($id < self::INT32_MIN || $id > self::INT32_MAX) {
            throw new InvalidArgumentException(sprintf('%s out of int32 range: %d', $field, $id));
        }

        return $id;
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
            if ($remainingUs <= 0) {
                throw new ConfigurationException(
                    sprintf('gRPC %d: deadline exceeded (attempt %d of %d)', STATUS_DEADLINE_EXCEEDED, $attempt, $retry->maxAttempts),
                    STATUS_DEADLINE_EXCEEDED,
                );
            }
            [$response, $status] = $start($metadata, ['timeout' => $remainingUs])->wait();
            if (!is_object($status) || !isset($status->code)) {
                throw new ConfigurationException(
                    sprintf('gRPC %d: status missing (attempt %d of %d)', STATUS_UNKNOWN, $attempt, $retry->maxAttempts),
                    STATUS_UNKNOWN,
                );
            }
            $code = (int) $status->code;
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
