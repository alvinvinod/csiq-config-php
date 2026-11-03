<?php

declare(strict_types=1);

namespace Csiq\Configuration;

/**
 * Override layer for an account configuration row. Matches CareStack.Configuration.OverrideLevel.
 */
final class OverrideLevel
{
    public const NONE = 0;
    public const VOICESTACK = 1;
    public const PRACTICE = 2;
    public const LOCATION_GROUP = 3;
    public const LOCATION = 4;
    public const USER = 5;

    /** @deprecated Use LOCATION_GROUP */
    public const REGION = 3;
}
