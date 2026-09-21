<?php

namespace SocketBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocketBridge\DTO\Envelope;
use Symfony\Component\Process\Process;

class ProtocolTest extends TestCase
{
    public function test_ids_follow_the_same_uuid_variant_contract_as_the_gateway(): void
    {
        self::assertTrue(Envelope::validId('20A788E6-D456-4288-A220-1B91539AAD2A'));
        self::assertFalse(Envelope::validId('00000000-0000-0000-0000-000000000000'));
        self::assertFalse(Envelope::validId('20a788e6-d456-9288-a220-1b91539aad2a'));
        self::assertFalse(Envelope::validId('20a788e6-d456-4288-f220-1b91539aad2a'));
    }

    public function test_default_prefix_is_stable_but_isolates_identically_named_applications(): void
    {
        $first = $this->readConfiguredPrefix('first-application-key');
        self::assertSame($first, $this->readConfiguredPrefix('first-application-key'));
        self::assertNotSame($first, $this->readConfiguredPrefix('second-application-key'));
        self::assertSame('chosen:prefix', $this->readConfiguredPrefix('second-application-key', 'chosen:prefix'));
    }

    private function readConfiguredPrefix(string $key, ?string $override = null): string
    {
        $root = dirname(__DIR__, 2);
        $code = 'require '.var_export($root.'/vendor/autoload.php', true).'; $config = require '.var_export($root.'/config/socket-bridge.php', true).'; echo $config["prefix"];';
        $process = new Process([PHP_BINARY, '-r', $code], $root, [
            'APP_KEY' => $key, 'APP_NAME' => 'Laravel', 'APP_ENV' => 'testing', 'SOCKET_BRIDGE_PREFIX' => $override ?? false,
        ]);
        $process->mustRun();

        return $process->getOutput();
    }

    public function test_empty_recipient_is_rejected_before_a_stream_write(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Envelope::userRoom('');
    }
}
