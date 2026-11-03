<?php

declare(strict_types=1);

namespace Csiq\Configuration;

use InvalidArgumentException;

/**
 * Retry policy for unary calls. Defaults: 3 attempts, 250ms backoff between
 * attempts (multiplier 1, so the cap stays 250ms), retrying INTERNAL, UNKNOWN
 * and UNAVAILABLE. Sleep is equal jitter: half the backoff plus a random
 * remainder, so workers do not retry in lockstep.
 */
final class RetryPolicy
{
    /**
     * @param list<int> $retryableStatusCodes Grpc\STATUS_* values
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $initialBackoffMs = 250,
        public readonly int $maxBackoffMs = 250,
        public readonly float $backoffMultiplier = 1.0,
        public readonly array $retryableStatusCodes = [
            \Grpc\STATUS_INTERNAL,
            \Grpc\STATUS_UNKNOWN,
            \Grpc\STATUS_UNAVAILABLE,
        ],
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1');
        }
        if ($initialBackoffMs < 0) {
            throw new InvalidArgumentException('initialBackoffMs must not be negative');
        }
        if ($maxBackoffMs < $initialBackoffMs) {
            throw new InvalidArgumentException('maxBackoffMs must be at least initialBackoffMs');
        }
        if ($backoffMultiplier < 1) {
            throw new InvalidArgumentException('backoffMultiplier must be at least 1');
        }
    }

    public function isRetryable(int $statusCode): bool
    {
        return in_array($statusCode, $this->retryableStatusCodes, true);
    }
}
