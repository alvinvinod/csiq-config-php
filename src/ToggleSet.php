<?php

declare(strict_types=1);

namespace Csiq\Configuration;

final class ToggleSet
{
    /**
     * @param array<string, bool> $toggles
     * @param array<string, mixed> $configurations
     */
    public function __construct(
        public readonly array $toggles,
        public readonly array $configurations,
    ) {
    }
}
