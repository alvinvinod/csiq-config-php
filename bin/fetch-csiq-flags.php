<?php

declare(strict_types=1);

/**
 * How CSIQ app code uses the package: a class receives IConfigurationClient.
 *
 *   php bin/fetch-csiq-flags.php
 *   php bin/fetch-csiq-flags.php --tenant-id=75025 --user-id=75015
 *   php bin/fetch-csiq-flags.php --tenant-id=75025 --user-id=75015 --region-id=1
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Csiq\Configuration\ConfigurationClientFactory;
use Csiq\Configuration\CsiqFlagCatalog;
use Csiq\Configuration\IConfigurationClient;

final class PracticeConfig
{
    public function __construct(
        private IConfigurationClient $config,
        private ?int $locationGroupId = null,
    ) {
    }

    public function isMemoEnabled(int $casTenantId): bool
    {
        return $this->config->isFeatureEnabled(
            'is_memo_enabled',
            $casTenantId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function isFaxEnabled(int $casTenantId): bool
    {
        return $this->config->isFeatureEnabled(
            'is_fax_enabled',
            $casTenantId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function isAudioDownloadEnabled(int $casTenantId, ?int $userId = null): bool
    {
        return $this->config->isFeatureEnabled(
            'is_audio_download_enabled',
            $casTenantId,
            userId: $userId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function transcriptionLanguages(int $casTenantId): mixed
    {
        return $this->config->tryGet(
            'transcription_languages',
            $casTenantId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function adminAppVersion(int $casTenantId, int $userId): mixed
    {
        return $this->config->tryGet(
            'admin_app_version',
            $casTenantId,
            userId: $userId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function agentAppVersion(int $casTenantId, int $userId): mixed
    {
        return $this->config->tryGet(
            'agent_app_version',
            $casTenantId,
            userId: $userId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function dump(int $casTenantId, int $userId): void
    {
        echo "casTenantId {$casTenantId}\n";
        echo '  memo: ' . ($this->isMemoEnabled($casTenantId) ? 'true' : 'false') . PHP_EOL;
        echo '  fax: ' . ($this->isFaxEnabled($casTenantId) ? 'true' : 'false') . PHP_EOL;
        echo '  audio download: ' . ($this->isAudioDownloadEnabled($casTenantId) ? 'true' : 'false') . PHP_EOL;
        echo '  languages: ' . json_encode($this->transcriptionLanguages($casTenantId)) . PHP_EOL;

        echo "user {$userId}\n";
        echo '  audio download: ' . ($this->isAudioDownloadEnabled($casTenantId, $userId) ? 'true' : 'false') . PHP_EOL;
        echo '  admin app: ' . json_encode($this->adminAppVersion($casTenantId, $userId)) . PHP_EOL;
        echo '  agent app: ' . json_encode($this->agentAppVersion($casTenantId, $userId)) . PHP_EOL;
    }
}

$opts = getopt('', ['tenant-id:', 'user-id:', 'region-id:']);
$casTenantId = isset($opts['tenant-id']) ? (int) $opts['tenant-id'] : 1;
$userId = isset($opts['user-id']) ? (int) $opts['user-id'] : 00100;
$regionId = isset($opts['region-id'])
    ? (int) $opts['region-id']
    : (CsiqFlagCatalog::env('CSIQ_CONFIG_REGION_ID') !== '' ? (int) CsiqFlagCatalog::env('CSIQ_CONFIG_REGION_ID') : null);

$practiceConfig = new PracticeConfig(ConfigurationClientFactory::create(), $regionId);
$practiceConfig->dump($casTenantId, $userId);
