<?php

declare(strict_types=1);

namespace Csiq\Configuration\Testing;

use Csiq\Configuration\ConfigDefinitionScope;
use Csiq\Configuration\ConfigurationDetails;
use Csiq\Configuration\ConfigurationException;
use Csiq\Configuration\IConfigurationClient;
use Csiq\Configuration\ToggleSet;

/** In-memory client keyed by name. Layer ids are ignored. */
final class FakeConfigurationClient implements IConfigurationClient
{
    private ?int $failureCode = null;

    /**
     * @param array<string, bool> $toggles
     * @param array<string, mixed> $configs
     */
    public function __construct(private array $toggles = [], private array $configs = [])
    {
    }

    public function failWith(int $grpcStatus): self
    {
        $this->failureCode = $grpcStatus;

        return $this;
    }

    public function isFeatureEnabled(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): bool {
        $this->check();

        return $this->toggles[$name] ?? false;
    }

    public function isDevToggleEnabled(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): bool {
        return $this->isFeatureEnabled($name, $casTenantId);
    }

    public function tryGet(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): mixed {
        $this->check();

        return $this->toggles[$name] ?? $this->configs[$name] ?? null;
    }

    public function getConfigurationDetails(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): ConfigurationDetails {
        if (!array_key_exists($name, $this->toggles) && !array_key_exists($name, $this->configs)) {
            throw new ConfigurationException("Entry not found for {$name}");
        }

        return new ConfigurationDetails($name, 0, 0, $this->tryGet($name, $casTenantId), $casTenantId);
    }

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
        $this->check();

        return [];
    }

    public function getAll(
        int $casTenantId,
        array $names = [],
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): ToggleSet {
        $this->check();
        $pick = static fn (array $all): array => $names === [] ? $all : array_intersect_key($all, array_flip($names));

        return new ToggleSet($pick($this->toggles), $pick($this->configs));
    }

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
        $this->check();
        if (is_bool($value)) {
            $this->toggles[$name] = $value;
        } else {
            $this->configs[$name] = $value;
        }
    }

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
        $this->check();
        unset($this->toggles[$name], $this->configs[$name]);
    }

    private function check(): void
    {
        if ($this->failureCode !== null) {
            throw new ConfigurationException('gRPC ' . $this->failureCode . ': fake failure', $this->failureCode);
        }
    }
}
