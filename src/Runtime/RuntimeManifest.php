<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use RuntimeException;

final class RuntimeManifest
{
    public readonly array $data;

    public function __construct(string $path)
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('The Socket Bridge runtime manifest is missing. Reinstall the Composer package.');
        }
        $this->data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (($this->data['schema'] ?? null) !== 1 || ($this->data['compatible_major'] ?? null) !== 24 || ($this->data['protocol'] ?? null) !== 1) {
            throw new RuntimeException('The runtime manifest schema, protocol or Node compatibility is unsupported. Reinstall a matching Composer package and gateway bundle.');
        }
        if (! preg_match('/^24\.\d+\.\d+$/', $this->data['node_version'] ?? '')) {
            throw new RuntimeException('Invalid Node version in the Socket Bridge runtime manifest.');
        }
    }

    public function version(): string
    {
        return $this->data['node_version'];
    }

    public function artifact(Platform $platform): array
    {
        if (! $platform->allowsOfficialDownload()) {
            throw new RuntimeException('This Linux system uses musl. Install a musl-compatible Node 24 yourself and set SOCKET_BRIDGE_NODE_BINARY, or use Docker. Official glibc archives cannot run here.');
        }
        $artifact = $this->data['artifacts'][$platform->key()] ?? null;
        if (! is_array($artifact) || ! preg_match('/^[a-f0-9]{64}$/', $artifact['sha256'] ?? '')) {
            throw new RuntimeException('No verified Node archive is available for '.$platform->key().'. Configure SOCKET_BRIDGE_NODE_BINARY with Node 24.');
        }
        $expected = 'https://nodejs.org/dist/v'.$this->version().'/node-v'.$this->version().'-'.$platform->key().'.tar.gz';
        if (($artifact['url'] ?? '') !== $expected) {
            throw new RuntimeException('Invalid runtime archive URL. Only the pinned official Node distribution is allowed.');
        }

        return $artifact;
    }
}
