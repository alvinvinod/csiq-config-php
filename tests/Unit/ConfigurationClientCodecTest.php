<?php

declare(strict_types=1);

namespace Csiq\Configuration\Tests\Unit;

use Carestack\Configuration\RpcConfigurationDetails;
use Carestack\Configuration\RpcConfigurationDetailsNullable;
use Carestack\Configuration\RpcUpsertConfigurationRequest;
use Csiq\Configuration\AttributeType;
use Csiq\Configuration\Tests\Support\RpcMocks;
use Google\Protobuf\GPBEmpty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationClientCodecTest extends TestCase
{
    use RpcMocks;

    /** @return array{int, string} type and JSON the client sends for $value */
    private function encoded(mixed $value): array
    {
        $client = $this->client();
        $sent = null;
        $this->rpc->method('UpsertConfiguration')->willReturnCallback(function (RpcUpsertConfigurationRequest $request) use (&$sent) {
            $sent = $request->getTypeValuePair();

            return $this->unaryCall(new GPBEmpty());
        });

        $client->upsert('flag', 1, $value, 9);

        return [$sent->getType(), $sent->getValueUnwrapped()];
    }

    private function decoded(int $type, ?string $json): mixed
    {
        $client = $this->client();
        $details = new RpcConfigurationDetails();
        $details->setTypeValuePair(self::pair($type, $json));
        $response = new RpcConfigurationDetailsNullable();
        $response->setConfigurationDetails($details);
        $this->rpc->method('TryGetConfigurationDetails')->willReturn($this->unaryCall($response));

        return $client->tryGet('flag', 1);
    }

    /** @return iterable<string, array{mixed, int, string}> */
    public static function encodings(): iterable
    {
        yield 'true' => [true, AttributeType::BOOLEAN, 'true'];
        yield 'false' => [false, AttributeType::BOOLEAN, 'false'];
        yield 'int' => [5, AttributeType::NUMBER, '5'];
        yield 'negative int' => [-3, AttributeType::NUMBER, '-3'];
        yield 'string' => ['x', AttributeType::STRING, '"x"'];
        yield 'empty string' => ['', AttributeType::STRING, '""'];
        yield 'list' => [['en-US', 'es-US'], AttributeType::JSON, '["en-US","es-US"]'];
        yield 'map' => [['a' => 1], AttributeType::JSON, '{"a":1}'];
        // Floats have no NUMBER encoding and go out as JSON.
        yield 'float' => [1.5, AttributeType::JSON, '1.5'];
        yield 'null' => [null, AttributeType::JSON, 'null'];
    }

    #[DataProvider('encodings')]
    public function testEncodesByPhpType(mixed $value, int $type, string $json): void
    {
        self::assertSame([$type, $json], $this->encoded($value));
    }

    /** @return iterable<string, array{int, ?string, mixed}> */
    public static function decodings(): iterable
    {
        yield 'boolean true' => [AttributeType::BOOLEAN, 'true', true];
        yield 'boolean false' => [AttributeType::BOOLEAN, 'false', false];
        yield 'bit 1' => [AttributeType::BIT, '1', true];
        yield 'bit 0' => [AttributeType::BIT, '0', false];
        yield 'number' => [AttributeType::NUMBER, '42', 42];
        yield 'string' => [AttributeType::STRING, '"hello"', 'hello'];
        yield 'date' => [AttributeType::DATE, '"2026-10-30T00:00:00"', '2026-10-30T00:00:00'];
        yield 'json object' => [AttributeType::JSON, '{"a":[1,2]}', ['a' => [1, 2]]];
        yield 'json list' => [AttributeType::JSON, '["en-US"]', ['en-US']];
        yield 'empty value' => [AttributeType::STRING, '', null];
        yield 'no value' => [AttributeType::BOOLEAN, null, null];
    }

    #[DataProvider('decodings')]
    public function testDecodesByAttributeType(int $type, ?string $json, mixed $expected): void
    {
        self::assertSame($expected, $this->decoded($type, $json));
    }

    /** @return iterable<string, array{mixed}> */
    public static function roundTrips(): iterable
    {
        yield 'bool' => [true];
        yield 'int' => [1234];
        yield 'string' => ['^\+1\d{10}$'];
        yield 'list' => [['en-US', 'es-US']];
        yield 'nested map' => [['languages' => ['en-US'], 'limit' => 3]];
    }

    #[DataProvider('roundTrips')]
    public function testRoundTrip(mixed $value): void
    {
        [$type, $json] = $this->encoded($value);

        self::assertSame($value, $this->decoded($type, $json));
    }
}
