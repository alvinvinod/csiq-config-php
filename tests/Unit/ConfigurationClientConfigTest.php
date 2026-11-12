<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Unit;

use Csiq\Configuration\ConfigurationClientConfig;
use Csiq\Configuration\ConfigurationClientFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationClientConfigTest extends TestCase
{
    private const VARIABLES = [
        'CONFIG_SERVICE_URI',
        'CONFIG_SERVICE_INSECURE',
        'CONFIG_SERVICE_TIMEOUT_MS',
        'CONFIG_SERVICE_RETRY_MAX_ATTEMPTS',
        'CONFIG_SERVICE_RETRY_INITIAL_BACKOFF_MS',
        'CONFIG_SERVICE_RETRY_MAX_BACKOFF_MS',
        'CONFIG_SERVICE_RETRY_BACKOFF_MULTIPLIER',
        'CONFIG_SERVICE_KEEPALIVE_TIME_MS',
        'CONFIG_SERVICE_KEEPALIVE_TIMEOUT_MS',
        'CONFIG_SERVICE_ROOT_CERT_PATH',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testDefaultsWhenNothingIsSet(): void
    {
        putenv('CONFIG_SERVICE_ROOT_CERT_PATH=/etc/csiq/config-ca.pem');
        $config = ConfigurationClientConfig::fromEnvironment();

        self::assertSame('127.0.0.1:8081', $config->host);
        self::assertFalse($config->insecure);
        self::assertSame(10000, $config->timeoutMs);
        self::assertSame(3, $config->retryPolicy->maxAttempts);
        self::assertSame(250, $config->retryPolicy->initialBackoffMs);
        self::assertSame(250, $config->retryPolicy->maxBackoffMs);
        self::assertSame(1.0, $config->retryPolicy->backoffMultiplier);
        self::assertSame(60000, $config->keepaliveTimeMs);
        self::assertSame(10000, $config->keepaliveTimeoutMs);
        self::assertSame('/etc/csiq/config-ca.pem', $config->rootCertPath);
    }

    public function testTlsRequiresRootCertPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rootCertPath is required for TLS');

        new ConfigurationClientConfig('127.0.0.1:8081');
    }

    public function testTlsRequiresRootCertPathFromEnvironment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rootCertPath is required for TLS');

        ConfigurationClientConfig::fromEnvironment();
    }

    public function testEmptyValuesFallBackToDefaults(): void
    {
        putenv('CONFIG_SERVICE_TIMEOUT_MS=');
        putenv('CONFIG_SERVICE_RETRY_MAX_ATTEMPTS=');
        putenv('CONFIG_SERVICE_INSECURE=');
        putenv('CONFIG_SERVICE_ROOT_CERT_PATH=/etc/csiq/config-ca.pem');

        $config = ConfigurationClientConfig::fromEnvironment();

        self::assertSame(10000, $config->timeoutMs);
        self::assertSame(3, $config->retryPolicy->maxAttempts);
        self::assertFalse($config->insecure);
    }

    public function testReadsEveryVariable(): void
    {
        putenv('CONFIG_SERVICE_URI=config.local:9000');
        putenv('CONFIG_SERVICE_INSECURE=true');
        putenv('CONFIG_SERVICE_TIMEOUT_MS=2500');
        putenv('CONFIG_SERVICE_RETRY_MAX_ATTEMPTS=3');
        putenv('CONFIG_SERVICE_RETRY_INITIAL_BACKOFF_MS=200');
        putenv('CONFIG_SERVICE_RETRY_MAX_BACKOFF_MS=800');
        putenv('CONFIG_SERVICE_RETRY_BACKOFF_MULTIPLIER=1.5');
        putenv('CONFIG_SERVICE_KEEPALIVE_TIME_MS=30000');
        putenv('CONFIG_SERVICE_KEEPALIVE_TIMEOUT_MS=5000');
        putenv('CONFIG_SERVICE_ROOT_CERT_PATH=/etc/csiq/config-ca.pem');

        $config = ConfigurationClientConfig::fromEnvironment();

        self::assertSame('config.local:9000', $config->host);
        self::assertTrue($config->insecure);
        self::assertSame(2500, $config->timeoutMs);
        self::assertSame(3, $config->retryPolicy->maxAttempts);
        self::assertSame(200, $config->retryPolicy->initialBackoffMs);
        self::assertSame(800, $config->retryPolicy->maxBackoffMs);
        self::assertSame(1.5, $config->retryPolicy->backoffMultiplier);
        self::assertSame(30000, $config->keepaliveTimeMs);
        self::assertSame(5000, $config->keepaliveTimeoutMs);
        self::assertSame('/etc/csiq/config-ca.pem', $config->rootCertPath);
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function insecureValues(): iterable
    {
        yield 'unset' => [null, false];
        yield 'empty' => ['', false];
        yield 'true' => ['true', true];
        yield '1' => ['1', true];
        yield 'false' => ['false', false];
        yield '0' => ['0', false];
    }

    #[DataProvider('insecureValues')]
    public function testInsecureFlag(?string $value, bool $expected): void
    {
        if ($value !== null) {
            putenv("CONFIG_SERVICE_INSECURE={$value}");
        }
        if ($expected === false) {
            putenv('CONFIG_SERVICE_ROOT_CERT_PATH=/etc/csiq/config-ca.pem');
        }

        self::assertSame($expected, ConfigurationClientConfig::fromEnvironment()->insecure);
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedValues(): iterable
    {
        yield 'timeout not a number' => ['CONFIG_SERVICE_TIMEOUT_MS', 'abc'];
        yield 'attempts not an integer' => ['CONFIG_SERVICE_RETRY_MAX_ATTEMPTS', '1.5'];
        yield 'initial backoff not a number' => ['CONFIG_SERVICE_RETRY_INITIAL_BACKOFF_MS', '1s'];
        yield 'max backoff not a number' => ['CONFIG_SERVICE_RETRY_MAX_BACKOFF_MS', 'five'];
        yield 'multiplier not a number' => ['CONFIG_SERVICE_RETRY_BACKOFF_MULTIPLIER', 'x2'];
        yield 'keepalive time not a number' => ['CONFIG_SERVICE_KEEPALIVE_TIME_MS', '1s'];
        yield 'keepalive timeout not a number' => ['CONFIG_SERVICE_KEEPALIVE_TIMEOUT_MS', 'ten'];
    }

    #[DataProvider('malformedValues')]
    public function testRejectsMalformedValuesNamingTheVariable(string $name, string $value): void
    {
        putenv("{$name}={$value}");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($name);

        ConfigurationClientConfig::fromEnvironment();
    }

    public function testConstructorDefaultsToTls(): void
    {
        $config = new ConfigurationClientConfig('127.0.0.1:8081', rootCertPath: '/etc/csiq/config-ca.pem');

        self::assertFalse($config->insecure);
        self::assertSame('/etc/csiq/config-ca.pem', $config->rootCertPath);
    }

    public function testRejectsTimeoutBelowOneMillisecond(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('timeoutMs');

        new ConfigurationClientConfig('127.0.0.1:8081', true, 0);
    }

    public function testRejectsKeepaliveTimeBelowOneMillisecond(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keepaliveTimeMs');

        new ConfigurationClientConfig('127.0.0.1:8081', keepaliveTimeMs: 0);
    }

    public function testRejectsKeepaliveTimeoutBelowOneMillisecond(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keepaliveTimeoutMs');

        new ConfigurationClientConfig('127.0.0.1:8081', keepaliveTimeoutMs: 0);
    }

    public function testMissingRootCertPathThrowsWhenOpeningTheChannel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rootCertPath');

        ConfigurationClientFactory::create(new ConfigurationClientConfig(
            'localhost:7154',
            rootCertPath: '/no/such/csiq-ca.pem',
        ));
    }
}
