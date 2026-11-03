<?php

declare(strict_types=1);

/**
 * Bootstraps a client (factory) and injects IConfigurationClient into app-shaped code.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Csiq\Configuration\ConfigurationClientFactory;
use Csiq\Configuration\ConfigurationException;
use Csiq\Configuration\IConfigurationClient;
use Csiq\Configuration\OverrideLevel;

final class ConfigurationSmoke
{
    public function __construct(private IConfigurationClient $config)
    {
    }

    public function run(int $practiceId = 10001): int
    {
        $enabled = $this->config->isFeatureEnabled('EnableFoo', $practiceId);
        echo 'EnableFoo (practice ' . $practiceId . '): ' . ($enabled ? 'true' : 'false') . PHP_EOL;

        $details = $this->config->getConfigurationDetails('EnableFoo', $practiceId);
        echo sprintf(
            'getConfigurationDetails: name=%s value=%s priority=%d type=%d accountId=%s',
            $details->name,
            $details->value ? 'true' : 'false',
            $details->priority,
            $details->type,
            $details->accountId ?? 'null',
        ) . PHP_EOL;

        $rows = $this->config->getAllAccountConfigurations(
            'EnableFoo',
            $practiceId,
            [OverrideLevel::PRACTICE, OverrideLevel::CARESTACK],
        );
        echo 'getAllAccountConfigurations layers: ' . count($rows) . PHP_EOL;
        foreach ($rows as $row) {
            echo sprintf(
                '  layer priority=%d value=%s accountId=%s',
                $row->priority,
                is_bool($row->value) ? ($row->value ? 'true' : 'false') : json_encode($row->value),
                $row->accountId ?? 'null',
            ) . PHP_EOL;
        }

        try {
            $this->config->getConfigurationDetails('DoesNotExist_csiq_php_smoke', $practiceId);
            echo 'missing config should have thrown' . PHP_EOL;

            return 1;
        } catch (ConfigurationException) {
            echo 'getConfigurationDetails missing: throws' . PHP_EOL;
        }

        $this->config->upsert('EnableFoo', $practiceId, !$enabled, 1);
        $flipped = $this->config->isFeatureEnabled('EnableFoo', $practiceId);
        echo 'after upsert: ' . ($flipped ? 'true' : 'false') . PHP_EOL;

        $this->config->upsert('EnableFoo', $practiceId, $enabled, 1);
        echo 'restored: ' . ($this->config->isFeatureEnabled('EnableFoo', $practiceId) ? 'true' : 'false') . PHP_EOL;

        return 0;
    }
}

$smoke = new ConfigurationSmoke(ConfigurationClientFactory::create());
exit($smoke->run());
