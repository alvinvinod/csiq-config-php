<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Unit;

use Carestack\Configuration\RpcToggleValue;
use Csiq\Configuration\ConfigurationClientConfig;
use Csiq\Configuration\ConfigurationException;
use Csiq\Configuration\RetryPolicy;
use Csiq\Configuration\Tests\Support\RpcMocks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_INTERNAL;
use const Grpc\STATUS_INVALID_ARGUMENT;
use const Grpc\STATUS_NOT_FOUND;
use const Grpc\STATUS_OK;
use const Grpc\STATUS_PERMISSION_DENIED;
use const Grpc\STATUS_UNAVAILABLE;
use const Grpc\STATUS_UNKNOWN;

/**
 * Retries sleep for real, with a 10ms initial / 40ms max backoff, so timing assertions are bounds.
 */
final class ConfigurationClientRetryTest extends TestCase
{
    use RpcMocks;

    /** @var list<int> */
    private array $timeouts = [];

    /**
     * @param list<int> $codes status per attempt; the last one repeats
     */
    private function failWith(array $codes, string $details = 'boom'): void
    {
        $attempt = 0;
        $this->rpc->method('GetFeatureToggleValue')->willReturnCallback(
            function ($request, array $metadata, array $options) use (&$attempt, $codes, $details) {
                $this->timeouts[] = $options['timeout'];
                $code = $codes[min($attempt++, count($codes) - 1)];
                $response = new RpcToggleValue();
                $response->setValue(true);

                return $this->unaryCall($code === STATUS_OK ? $response : null, $code, $details);
            },
        );
    }

    public function testSucceedsOnFirstAttempt(): void
    {
        $client = $this->client();
        $this->failWith([STATUS_OK]);

        self::assertTrue($client->isFeatureEnabled('flag', 1));
        self::assertCount(1, $this->timeouts);
    }

    public function testRetriesRetryableCodesUntilSuccess(): void
    {
        $client = $this->client();
        $this->failWith([STATUS_UNAVAILABLE, STATUS_INTERNAL, STATUS_UNKNOWN, STATUS_OK]);

        self::assertTrue($client->isFeatureEnabled('flag', 1));
        self::assertCount(4, $this->timeouts);
    }

    public function testGivesUpAfterMaxAttemptsWithLastStatus(): void
    {
        $client = $this->client();
        $this->failWith([STATUS_UNAVAILABLE], 'connection refused');

        try {
            $client->isFeatureEnabled('flag', 1);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertSame(STATUS_UNAVAILABLE, $e->getCode());
            self::assertSame('gRPC 14: connection refused (attempt 5 of 5)', $e->getMessage());
        }
        self::assertCount(5, $this->timeouts);
    }

    public function testMaxAttemptsOfOneNeverRetries(): void
    {
        $client = $this->client(self::fastConfig(maxAttempts: 1));
        $this->failWith([STATUS_UNAVAILABLE]);

        $this->expectExceptionMessage('(attempt 1 of 1)');
        try {
            $client->isFeatureEnabled('flag', 1);
        } finally {
            self::assertCount(1, $this->timeouts);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function nonRetryableCodes(): iterable
    {
        yield 'INVALID_ARGUMENT' => [STATUS_INVALID_ARGUMENT];
        yield 'NOT_FOUND' => [STATUS_NOT_FOUND];
        yield 'DEADLINE_EXCEEDED' => [STATUS_DEADLINE_EXCEEDED];
        yield 'PERMISSION_DENIED' => [STATUS_PERMISSION_DENIED];
    }

    #[DataProvider('nonRetryableCodes')]
    public function testFailsImmediatelyOnNonRetryableCodes(int $code): void
    {
        $client = $this->client();
        $this->failWith([$code]);

        $started = hrtime(true);
        try {
            $client->isFeatureEnabled('flag', 1);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertSame($code, $e->getCode());
            self::assertStringEndsWith('(attempt 1 of 5)', $e->getMessage());
        }
        self::assertCount(1, $this->timeouts);
        self::assertLessThan(20, (hrtime(true) - $started) / 1e6, 'no backoff sleep expected');
    }

    public function testSleepsTheConfiguredBackoffBetweenAttempts(): void
    {
        $client = $this->client(new ConfigurationClientConfig(
            '127.0.0.1:1',
            true,
            5000,
            new RetryPolicy(2, 50, 50, 1.0),
        ));
        $this->failWith([STATUS_UNAVAILABLE]);

        $started = hrtime(true);
        try {
            $client->isFeatureEnabled('flag', 1);
        } catch (ConfigurationException) {
        }

        $elapsedMs = (hrtime(true) - $started) / 1e6;
        // Equal jitter on 50ms is 25..50ms.
        self::assertGreaterThanOrEqual(25, $elapsedMs);
        self::assertLessThan(150, $elapsedMs);
        self::assertCount(2, $this->timeouts);
    }

    public function testTotalBackoffStaysUnderTheCappedSum(): void
    {
        $client = $this->client();
        $this->failWith([STATUS_UNAVAILABLE]);

        $started = hrtime(true);
        try {
            $client->isFeatureEnabled('flag', 1);
        } catch (ConfigurationException) {
        }
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        // Backoff caps before retries 2..5 are 10, 20, 40, 40 ms.
        self::assertLessThan(110 + 100, $elapsedMs);
    }

    public function testDeadlineBoundsTheWholeCallIncludingBackoff(): void
    {
        $config = new ConfigurationClientConfig('127.0.0.1:1', true, 150, new RetryPolicy(5, 200, 200, 1.0));
        $client = $this->client($config);
        $this->failWith([STATUS_UNAVAILABLE]);

        $started = hrtime(true);
        try {
            $client->isFeatureEnabled('flag', 1);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertSame(STATUS_UNAVAILABLE, $e->getCode());
        }

        self::assertLessThan(150 + 50, (hrtime(true) - $started) / 1e6);
    }

    public function testEachAttemptGetsTheTimeLeftOnTheDeadline(): void
    {
        $client = $this->client(self::fastConfig(timeoutMs: 2000));
        $this->failWith([STATUS_UNAVAILABLE]);

        try {
            $client->isFeatureEnabled('flag', 1);
        } catch (ConfigurationException) {
        }

        self::assertCount(5, $this->timeouts);
        self::assertLessThanOrEqual(2_000_000, $this->timeouts[0]);
        self::assertGreaterThan(1_990_000, $this->timeouts[0]);
        for ($i = 1; $i < count($this->timeouts); $i++) {
            self::assertLessThan($this->timeouts[$i - 1], $this->timeouts[$i]);
            self::assertGreaterThanOrEqual(1, $this->timeouts[$i]);
        }
    }

    public function testEveryRpcGoesThroughTheRetryLoop(): void
    {
        $client = $this->client(self::fastConfig(maxAttempts: 2));
        $calls = [];
        foreach ([
            'GetDevToggleValue', 'TryGetConfigurationDetails', 'GetConfigurationDetails',
            'GetAllAccountConfigurationByNamesAndPriorities', 'GetAllTogglesAndConfigurations',
            'GetAllTogglesAndConfigurationsByNames', 'UpsertConfiguration', 'DeleteConfiguration',
        ] as $method) {
            $calls[$method] = 0;
            $this->rpc->method($method)->willReturnCallback(function () use (&$calls, $method) {
                $calls[$method]++;

                return $this->unaryCall(null, STATUS_UNAVAILABLE);
            });
        }

        $operations = [
            'GetDevToggleValue' => static fn ($c) => $c->isDevToggleEnabled('flag', 1),
            'TryGetConfigurationDetails' => static fn ($c) => $c->tryGet('flag', 1),
            'GetConfigurationDetails' => static fn ($c) => $c->getConfigurationDetails('flag', 1),
            'GetAllAccountConfigurationByNamesAndPriorities' => static fn ($c) => $c->getAllAccountConfigurations('flag', 1, [2]),
            'GetAllTogglesAndConfigurations' => static fn ($c) => $c->getAll(1),
            'GetAllTogglesAndConfigurationsByNames' => static fn ($c) => $c->getAll(1, ['flag']),
            'UpsertConfiguration' => static fn ($c) => $c->upsert('flag', 1, true, 9),
            'DeleteConfiguration' => static fn ($c) => $c->delete('flag', 1, 9),
        ];
        foreach ($operations as $method => $operation) {
            try {
                $operation($client);
                self::fail("{$method}: expected ConfigurationException");
            } catch (ConfigurationException $e) {
                self::assertSame(STATUS_UNAVAILABLE, $e->getCode(), $method);
            }
            self::assertSame(2, $calls[$method], "{$method} attempts");
        }
    }

    public function testDoesNotLogAFirstAttemptSuccess(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('log');
        $client = $this->client(self::fastConfig(maxAttempts: 2), $logger);
        $this->failWith([STATUS_OK]);

        self::assertTrue($client->isFeatureEnabled('flag', 1));
    }

    public function testLogsARetryThenGiveUp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $levels = [];
        $logger->expects(self::exactly(2))->method('log')->willReturnCallback(
            function (string $level, string $message, array $context) use (&$levels): void {
                $levels[] = [$level, $context['attempt'], $context['will_retry']];
                self::assertArrayHasKey('elapsed_ms', $context);
                self::assertArrayHasKey('code', $context);
                self::assertStringContainsString('attempt', $message);
            }
        );
        $client = $this->client(self::fastConfig(maxAttempts: 2), $logger);
        $this->failWith([STATUS_UNAVAILABLE]);

        try {
            $client->isFeatureEnabled('flag', 1);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException) {
        }

        self::assertSame([[LogLevel::WARNING, 1, true], [LogLevel::ERROR, 2, false]], $levels);
    }

    public function testLogsSuccessAfterRetry(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $levels = [];
        $logger->expects(self::exactly(2))->method('log')->willReturnCallback(
            function (string $level, string $message, array $context) use (&$levels): void {
                $levels[] = [$level, $context['code'], $context['will_retry']];
            }
        );
        $client = $this->client(self::fastConfig(maxAttempts: 3), $logger);
        $this->failWith([STATUS_UNAVAILABLE, STATUS_OK]);

        self::assertTrue($client->isFeatureEnabled('flag', 1));
        self::assertSame([[LogLevel::WARNING, STATUS_UNAVAILABLE, true], [LogLevel::WARNING, STATUS_OK, false]], $levels);
    }
}
