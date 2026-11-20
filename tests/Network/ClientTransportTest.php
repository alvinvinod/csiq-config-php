<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Network;

use Csiq\Configuration\ConfigurationClientConfig;
use Csiq\Configuration\ConfigurationClientFactory;
use Csiq\Configuration\ConfigurationException;
use Csiq\Configuration\RetryPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_UNAVAILABLE;

/**
 * Real gRPC channels against local sockets; no config service needed.
 */
#[Group('network')]
final class ClientTransportTest extends TestCase
{
    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }
    }

    /** @return array{resource, string} listening socket that never speaks HTTP/2, and its host:port */
    private function silentServer(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, $error);
        $this->sockets[] = $server;

        return [$server, (string) stream_socket_get_name($server, false)];
    }

    private function acceptPending($server): int
    {
        $accepted = 0;
        while (true) {
            $read = [$server];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 50_000) < 1) {
                return $accepted;
            }
            $connection = @stream_socket_accept($server, 0);
            if ($connection === false) {
                return $accepted;
            }
            $this->sockets[] = $connection;
            $accepted++;
        }
    }

    public function testRefusedPortRetriesThreeTimesWithTheDefaultPolicy(): void
    {
        $client = ConfigurationClientFactory::create(new ConfigurationClientConfig('127.0.0.1:1', true, 20000));

        $started = hrtime(true);
        try {
            $client->isFeatureEnabled('flag', 1);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertSame(STATUS_UNAVAILABLE, $e->getCode());
            self::assertStringEndsWith('(attempt 3 of 3)', $e->getMessage());
        }

        // Equal jitter on 250ms before retries 2 and 3 is at most 500ms of sleep.
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
    }

    public function testTimeoutCutsOffAServerThatNeverAnswers(): void
    {
        [, $address] = $this->silentServer();
        $client = ConfigurationClientFactory::create(new ConfigurationClientConfig($address, true, 500));

        $started = hrtime(true);
        try {
            $client->isFeatureEnabled('flag', 1);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertSame(STATUS_DEADLINE_EXCEEDED, $e->getCode());
            self::assertStringEndsWith('(attempt 1 of 3)', $e->getMessage());
        }

        $elapsed = (hrtime(true) - $started) / 1e9;
        self::assertGreaterThanOrEqual(0.45, $elapsed);
        self::assertLessThan(1.5, $elapsed);
    }

    public function testClientsInOneProcessShareOneConnection(): void
    {
        [$server, $address] = $this->silentServer();
        $config = new ConfigurationClientConfig($address, true, 200, new RetryPolicy(1));

        $connections = 0;
        for ($i = 0; $i < 3; $i++) {
            try {
                ConfigurationClientFactory::create($config)->isFeatureEnabled('flag', 1);
            } catch (ConfigurationException) {
            }
            $connections += $this->acceptPending($server);
        }

        self::assertSame(1, $connections);
    }
}
