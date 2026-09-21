<?php

namespace SocketBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocketBridge\DTO\Json;

class JsonTest extends TestCase
{
    public function test_decode_preserves_empty_and_numeric_key_objects_without_changing_named_field_arrays(): void
    {
        $json = '{"object":{},"list":[],"numeric":{"0":"zero","1":[]},"reordered":{"1":"one","0":{}},"named":{"name":"Ada","items":[{},[]]}}';
        $value = Json::decode($json);
        self::assertIsArray($value);
        self::assertInstanceOf(\stdClass::class, $value['object']);
        self::assertSame([], $value['list']);
        self::assertInstanceOf(\stdClass::class, $value['numeric']);
        self::assertInstanceOf(\stdClass::class, $value['reordered']);
        self::assertIsArray($value['named']);
        self::assertEquals(json_decode($json), json_decode(Json::encode($value)));
    }

    public function test_canonical_objects_are_order_independent_but_never_equal_to_lists(): void
    {
        $canonical = fn ($json) => Json::encode(Json::canonical(Json::decode($json)));
        self::assertSame($canonical('{"1":{},"0":[]}'), $canonical('{"0":[],"1":{}}'));
        self::assertNotSame($canonical('{}'), $canonical('[]'));
        self::assertNotSame($canonical('{"0":"zero"}'), $canonical('["zero"]'));
        self::assertNotSame($canonical('{"nested":{}}'), $canonical('{"nested":[]}'));
    }

    public function test_wire_payload_lists_are_rejected_instead_of_becoming_empty_objects(): void
    {
        foreach (['[]', '[1]'] as $payload) {
            try {
                Json::decodeEnvelope('{"type":"socket.command","payload":'.$payload.'}');
                self::fail('JSON list payloads must not be normalized into objects.');
            } catch (\JsonException) {
                self::assertTrue(true);
            }
        }
        self::assertInstanceOf(\stdClass::class, Json::decodeEnvelope('{"type":"socket.command","payload":{"0":"zero"}}')['payload']);
    }
}
