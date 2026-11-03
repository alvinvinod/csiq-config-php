<?php

declare(strict_types=1);

namespace Csiq\Configuration;

/**
 * One configuration row, including which override layer it came from.
 * Matches CareStack.Configuration.ConfigurationDetails.
 */
final class ConfigurationDetails
{
    public function __construct(
        public readonly string $name,
        public readonly int $priority,
        public readonly int $type,
        public readonly mixed $value,
        public readonly ?int $accountId = null,
        public readonly ?int $userId = null,
        public readonly ?int $locationGroupId = null,
        public readonly ?int $locationId = null,
    ) {
    }
}
