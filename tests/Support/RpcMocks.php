<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Support;

use Carestack\Configuration\RpcTypeValuePair;
use Csiq\Configuration\ConfigurationClient;
use Csiq\Configuration\ConfigurationClientConfig;
use Csiq\Configuration\RetryPolicy;
use Csiq\Configuration\RpcConfigurationServiceClient;
use Grpc\UnaryCall;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

use const Grpc\STATUS_OK;

/**
 * Builds a ConfigurationClient over a mocked RPC stub, the PHP version of the C# tests'
 * Mock<RpcConfigurationServiceClient> plus ToUnaryCallAsync().
 */
trait RpcMocks
{
    /** @var RpcConfigurationServiceClient&MockObject */
    private RpcConfigurationServiceClient $rpc;

    private function client(?ConfigurationClientConfig $config = null, ?LoggerInterface $logger = null): ConfigurationClient
    {
        $this->rpc = $this->createMock(RpcConfigurationServiceClient::class);

        return new ConfigurationClient($this->rpc, $config ?? self::fastConfig(), $logger);
    }

    private static function fastConfig(int $timeoutMs = 5000, int $maxAttempts = 5): ConfigurationClientConfig
    {
        return new ConfigurationClientConfig('127.0.0.1:1', true, $timeoutMs, new RetryPolicy($maxAttempts, 10, 40, 2.0));
    }

    private function unaryCall(mixed $response, int $code = STATUS_OK, string $details = ''): UnaryCall
    {
        $call = $this->createMock(UnaryCall::class);
        $call->method('wait')->willReturn([$response, (object) ['code' => $code, 'details' => $details, 'metadata' => []]]);

        return $call;
    }

    private static function pair(int $type, ?string $json): RpcTypeValuePair
    {
        $pair = new RpcTypeValuePair();
        $pair->setType($type);
        if ($json !== null) {
            $pair->setValueUnwrapped($json);
        }

        return $pair;
    }
}
