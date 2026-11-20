<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Unit;

use Csiq\Configuration\ProductionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProductionGuardTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function localUris(): iterable
    {
        yield 'ipv4 loopback' => ['127.0.0.1:7154'];
        yield 'ipv4 loopback other octet' => ['127.0.0.2:7154'];
        yield 'unspecified ipv4' => ['0.0.0.0:7154'];
        yield 'localhost' => ['localhost:7154'];
        yield 'localhost trailing dot' => ['localhost.:7154'];
        yield 'ipv6 brackets' => ['[::1]:7154'];
        yield 'ipv6 mapped loopback' => ['[::ffff:127.0.0.2]:7154'];
        yield 'https scheme' => ['https://127.0.0.2:7154'];
    }

    #[DataProvider('localUris')]
    public function testDetectsLoopbackHosts(string $uri): void
    {
        self::assertTrue(ProductionGuard::isLocalHost($uri));
    }

    public function testRemoteHostIsNotLoopback(): void
    {
        self::assertFalse(ProductionGuard::isLocalHost('config.example.com:443'));
        self::assertFalse(ProductionGuard::isLocalHost('10.0.0.1:7154'));
        self::assertFalse(ProductionGuard::isLocalHost(''));
    }

    public function testScriptsAllowLoopbackWithoutRemoteFlag(): void
    {
        ProductionGuard::assertRemoteAllowed('127.0.0.2:7154', true, false);
        $this->addToAssertionCount(1);
    }

    public function testScriptsRejectRemoteWithoutFlag(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--allow-remote');

        ProductionGuard::assertRemoteAllowed('config.example.com:443', false, false);
    }

    public function testScriptsRejectInsecureRemoteEvenWithFlag(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('insecure');

        ProductionGuard::assertRemoteAllowed('config.example.com:443', true, true);
    }

    public function testScriptsAllowTlsRemoteWithFlag(): void
    {
        ProductionGuard::assertRemoteAllowed('config.example.com:443', false, true);
        $this->addToAssertionCount(1);
    }
}
