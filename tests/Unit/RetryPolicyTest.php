<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Unit;

use Csiq\Configuration\RetryPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testDefaultsAreThreeAttemptsWithAFlat250MsBackoff(): void
    {
        $policy = new RetryPolicy();

        self::assertSame(3, $policy->maxAttempts);
        self::assertSame(250, $policy->initialBackoffMs);
        self::assertSame(250, $policy->maxBackoffMs);
        self::assertSame(1.0, $policy->backoffMultiplier);
        self::assertSame([\Grpc\STATUS_INTERNAL, \Grpc\STATUS_UNKNOWN, \Grpc\STATUS_UNAVAILABLE], $policy->retryableStatusCodes);
    }

    /** @return iterable<string, array{0: callable(): RetryPolicy, 1: string}> */
    public static function invalidPolicies(): iterable
    {
        yield 'zero attempts' => [static fn () => new RetryPolicy(maxAttempts: 0), 'maxAttempts'];
        yield 'negative initial backoff' => [static fn () => new RetryPolicy(initialBackoffMs: -1), 'initialBackoffMs'];
        yield 'max below initial' => [static fn () => new RetryPolicy(initialBackoffMs: 2000, maxBackoffMs: 1000), 'maxBackoffMs'];
        yield 'multiplier below one' => [static fn () => new RetryPolicy(backoffMultiplier: 0.5), 'backoffMultiplier'];
    }

    #[DataProvider('invalidPolicies')]
    public function testRejectsInvalidSettings(callable $build, string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($field);

        $build();
    }

    /** @return iterable<string, array{int, bool}> */
    public static function statusCodes(): iterable
    {
        yield 'INTERNAL' => [\Grpc\STATUS_INTERNAL, true];
        yield 'UNKNOWN' => [\Grpc\STATUS_UNKNOWN, true];
        yield 'UNAVAILABLE' => [\Grpc\STATUS_UNAVAILABLE, true];
        yield 'OK' => [\Grpc\STATUS_OK, false];
        yield 'INVALID_ARGUMENT' => [\Grpc\STATUS_INVALID_ARGUMENT, false];
        yield 'DEADLINE_EXCEEDED' => [\Grpc\STATUS_DEADLINE_EXCEEDED, false];
        yield 'NOT_FOUND' => [\Grpc\STATUS_NOT_FOUND, false];
        yield 'UNAUTHENTICATED' => [\Grpc\STATUS_UNAUTHENTICATED, false];
    }

    #[DataProvider('statusCodes')]
    public function testIsRetryable(int $code, bool $expected): void
    {
        self::assertSame($expected, (new RetryPolicy())->isRetryable($code));
    }
}
