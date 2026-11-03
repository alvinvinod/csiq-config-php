<?php

declare(strict_types=1);

namespace Csiq\Configuration;

use Psr\Log\LoggerInterface;

/**
 * Assembles IConfigurationClient for scripts that have no container.
 */
final class ConfigurationClientFactory
{
    public static function create(?ConfigurationClientConfig $config = null, ?LoggerInterface $logger = null): IConfigurationClient
    {
        $config ??= ConfigurationClientConfig::fromEnvironment();

        return new ConfigurationClient(new RpcConfigurationServiceClient($config), $config, $logger);
    }
}
