<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Unit;

use Csiq\Configuration\ConfigurationException;
use Csiq\Configuration\Testing\FakeConfigurationClient;
use PHPUnit\Framework\TestCase;

final class FakeConfigurationClientTest extends TestCase
{
    public function testMissingToggleReadsAsFalse(): void
    {
        $fake = new FakeConfigurationClient();

        self::assertFalse($fake->isFeatureEnabled('is_memo_enabled', 1));
    }

    public function testTryGetPrefersTogglesThenConfigs(): void
    {
        $fake = new FakeConfigurationClient(
            ['is_memo_enabled' => true],
            ['dni_regex' => '^[0-9]+$'],
        );

        self::assertTrue($fake->tryGet('is_memo_enabled', 1));
        self::assertSame('^[0-9]+$', $fake->tryGet('dni_regex', 1));
        self::assertNull($fake->tryGet('missing', 1));
    }

    public function testGetAllFiltersByName(): void
    {
        $fake = new FakeConfigurationClient(
            ['is_memo_enabled' => true, 'is_fax_enabled' => false],
            ['dni_regex' => 'x', 'other' => 'y'],
        );

        $set = $fake->getAll(1, ['is_memo_enabled', 'dni_regex']);

        self::assertSame(['is_memo_enabled' => true], $set->toggles);
        self::assertSame(['dni_regex' => 'x'], $set->configurations);
    }

    public function testUpsertAndDelete(): void
    {
        $fake = new FakeConfigurationClient();
        $fake->upsert('is_memo_enabled', 1, true, 9);
        $fake->upsert('dni_regex', 1, 'ab', 9);

        self::assertTrue($fake->isFeatureEnabled('is_memo_enabled', 1));
        self::assertSame('ab', $fake->tryGet('dni_regex', 1));

        $fake->delete('is_memo_enabled', 1, 9);
        self::assertFalse($fake->isFeatureEnabled('is_memo_enabled', 1));
    }

    public function testFailWithThrowsOnReads(): void
    {
        $fake = (new FakeConfigurationClient(['is_memo_enabled' => true]))->failWith(14);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(14);

        $fake->isFeatureEnabled('is_memo_enabled', 1);
    }

    public function testGetConfigurationDetailsReturnsFalseToggle(): void
    {
        $fake = new FakeConfigurationClient(['is_memo_enabled' => false]);
        $details = $fake->getConfigurationDetails('is_memo_enabled', 9);

        self::assertSame('is_memo_enabled', $details->name);
        self::assertFalse($details->value);
        self::assertSame(9, $details->accountId);
    }

    public function testGetConfigurationDetailsThrowsWhenMissing(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Entry not found');

        (new FakeConfigurationClient())->getConfigurationDetails('nope', 1);
    }
}
