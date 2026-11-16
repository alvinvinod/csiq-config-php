<?php

declare(strict_types=1);

namespace Csiq\Configuration;

/**
 * Read and write CareStack configuration over gRPC.
 *
 * Every method takes a CAS tenant id. Optional ids
 * narrow the override layer the service resolves: user, location, location group,
 * attribute area. Omitted ids are not sent. Scope defaults to ConfigDefinitionScope::SERVER.
 *
 * The service picks the winning layer. This client does not.
 * Calls block the PHP worker until the reply, the retry budget, or the deadline.
 * A transport failure throws ConfigurationException. Its code is the gRPC status.
 */
interface IConfigurationClient
{
    /**
     * Resolved feature toggle.
     *
     * False when the toggle is off, when the definition exists but no value is stored,
     * and when the name is not a definition at all. Those three cases are not distinguishable here.
     * The name must be a feature toggle. Reading a config or a dev toggle through this method
     * fails the call.
     */
    public function isFeatureEnabled(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): bool;

    /**
     * Resolved dev toggle. Same missing-name behavior as isFeatureEnabled(): false.
     *
     * The name must be a dev toggle.
     */
    public function isDevToggleEnabled(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): bool;

    /**
     * Resolved value for one name, or null when nothing matches.
     *
     * Null means the name is not a definition, or it is a non-toggle config with no stored value.
     * A feature or dev toggle with no stored value comes back as false, not null: the service
     * synthesizes that default.
     *
     * Decoded by the definition type: bool, int, string, or array.
     */
    public function tryGet(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): mixed;

    /**
     * Resolved winner for one name, including which override layer it came from.
     *
     * Throws ConfigurationException when the service reports no entry. Prefer tryGet when
     * a missing name is expected. On the current service a missing name is status UNKNOWN,
     * which this client retries.
     */
    public function getConfigurationDetails(
        string $name,
        int $casTenantId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): ConfigurationDetails;

    /**
     * Stored rows for one name at the given override layers. Not the resolved winner.
     *
     * A toggle definition with no stored value is absent here, even though isFeatureEnabled()
     * and tryGet() report false for it.
     *
     * @param list<int> $overrideLevels OverrideLevel::* values
     *
     * @return list<ConfigurationDetails>
     */
    public function getAllAccountConfigurations(
        string $name,
        int $casTenantId,
        array $overrideLevels,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): array;

    /**
     * Resolved toggles and configurations in one RPC.
     *
     * Pass $names for only those keys (GetAllTogglesAndConfigurationsByNames). An empty
     * list asks for every definition the service resolves for the condition
     * (GetAllTogglesAndConfigurations), including ones this app did not create.
     * Defined toggles with no stored row come back as false. Config names with no value
     * and unknown names are omitted; use array_key_exists, not a truthiness test.
     *
     * @param list<string> $names
     */
    public function getAll(
        int $casTenantId,
        array $names = [],
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): ToggleSet;

    /**
     * Insert or replace the value at the layer described by the optional ids.
     *
     * The definition must already exist. This call does not create one.
     * bool is stored as boolean, int as number, string as string, anything else as JSON.
     * A lost response can be retried, so a write may be sent more than once.
     */
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
    ): void;

    /**
     * Remove the stored value at the layer described by the optional ids.
     *
     * The definition stays. A following read of a feature or dev toggle is false, the
     * synthesized default, not "missing".
     */
    public function delete(
        string $name,
        int $casTenantId,
        int $deletedByUserId,
        ?int $userId = null,
        ?int $locationId = null,
        ?int $locationGroupId = null,
        ?int $attributeArea = null,
        int $configDefinitionScope = ConfigDefinitionScope::SERVER,
    ): void;
}
