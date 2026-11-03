<?php

declare(strict_types=1);

/**
 * How CSIQ app code uses the package: a class receives IConfigurationClient.
 *
 *   php bin/fetch-csiq-flags.php
 *   php bin/fetch-csiq-flags.php --practice-id=75025 --user-id=75015
 *   php bin/fetch-csiq-flags.php --practice-id=75025 --user-id=75015 --region-id=1
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

    public function isMemoEnabled(int $practiceId): bool
    {
        return $this->config->isFeatureEnabled(
            'is_memo_enabled',
            $practiceId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function isFaxEnabled(int $practiceId): bool
    {
        return $this->config->isFeatureEnabled(
            'is_fax_enabled',
            $practiceId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function isAudioDownloadEnabled(int $practiceId, ?int $userId = null): bool
    {
        return $this->config->isFeatureEnabled(
            'is_audio_download_enabled',
            $practiceId,
            userId: $userId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function transcriptionLanguages(int $practiceId): mixed
    {
        return $this->config->tryGet(
            'transcription_languages',
            $practiceId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function adminAppVersion(int $practiceId, int $userId): mixed
    {
        return $this->config->tryGet(
            'admin_app_version',
            $practiceId,
            userId: $userId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function agentAppVersion(int $practiceId, int $userId): mixed
    {
        return $this->config->tryGet(
            'agent_app_version',
            $practiceId,
            userId: $userId,
            locationGroupId: $this->locationGroupId,
        );
    }

    public function dump(int $practiceId, int $userId): void
    {
        echo "practice {$practiceId}\n";
        echo '  memo: ' . ($this->isMemoEnabled($practiceId) ? 'true' : 'false') . PHP_EOL;
        echo '  fax: ' . ($this->isFaxEnabled($practiceId) ? 'true' : 'false') . PHP_EOL;
        echo '  audio download: ' . ($this->isAudioDownloadEnabled($practiceId) ? 'true' : 'false') . PHP_EOL;
        echo '  languages: ' . json_encode($this->transcriptionLanguages($practiceId)) . PHP_EOL;

        echo "user {$userId}\n";
        echo '  audio download: ' . ($this->isAudioDownloadEnabled($practiceId, $userId) ? 'true' : 'false') . PHP_EOL;
        echo '  admin app: ' . json_encode($this->adminAppVersion($practiceId, $userId)) . PHP_EOL;
        echo '  agent app: ' . json_encode($this->agentAppVersion($practiceId, $userId)) . PHP_EOL;
    }
}

$opts = getopt('', ['practice-id:', 'user-id:', 'region-id:']);
$practiceId = isset($opts['practice-id']) ? (int) $opts['practice-id'] : 1;
$userId = isset($opts['user-id']) ? (int) $opts['user-id'] : 00100;
$regionId = isset($opts['region-id'])
    ? (int) $opts['region-id']
    : (CsiqFlagCatalog::env('CSIQ_CONFIG_REGION_ID') !== '' ? (int) CsiqFlagCatalog::env('CSIQ_CONFIG_REGION_ID') : null);

$practiceConfig = new PracticeConfig(ConfigurationClientFactory::create(), $regionId);
$practiceConfig->dump($practiceId, $userId);
