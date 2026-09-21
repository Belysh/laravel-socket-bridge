<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use RuntimeException;

final class Platform
{
    public function __construct(
        public readonly string $os,
        public readonly string $architecture,
        public readonly bool $musl = false,
    ) {}

    public static function detect(): self
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            default => throw new RuntimeException('Native Socket Bridge supports macOS and Linux. Use WSL2 or Docker on Windows.'),
        };
        $architecture = match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'x64',
            'arm64', 'aarch64' => 'arm64',
            default => throw new RuntimeException('Unsupported CPU architecture. Configure SOCKET_BRIDGE_NODE_BINARY with a compatible Node 24 executable.'),
        };

        return new self($os, $architecture, $os === 'linux' && count(glob('/lib/ld-musl-*.so.1') ?: []) > 0);
    }

    public function key(): string
    {
        return $this->os.'-'.$this->architecture.($this->musl ? '-musl' : '');
    }

    public function allowsOfficialDownload(): bool
    {
        return ! $this->musl || ($this->os === 'linux' && $this->architecture === 'x64');
    }
}
