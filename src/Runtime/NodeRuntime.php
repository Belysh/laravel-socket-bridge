<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Resolves Node without changing the system installation or the user's PATH. */
final class NodeRuntime
{
    public function __construct(
        private readonly RuntimeManifest $manifest,
        private readonly string $cacheDirectory,
        private readonly ?string $configuredBinary = null,
        private readonly ?Platform $platform = null,
    ) {}

    public function find(): ?string
    {
        if ($this->configuredBinary !== null && $this->configuredBinary !== '') {
            if (! str_starts_with($this->configuredBinary, '/')) {
                throw new RuntimeException('SOCKET_BRIDGE_NODE_BINARY must be an absolute path to a Node 24 executable.');
            }

            return $this->validate($this->configuredBinary);
        }

        $system = (new ExecutableFinder)->find('node');
        if ($system !== null) {
            try {
                return $this->validate($system);
            } catch (RuntimeException) {
                // Other Node majors remain untouched; use our private pinned runtime.
            }
        }

        $cached = $this->directory().'/bin/node';
        if (is_file($cached)) {
            return $this->validate($cached, $this->manifest->version());
        }

        return null;
    }

    public function resolve(bool $download = true, ?callable $progress = null): string
    {
        if (($node = $this->find()) !== null) {
            return $node;
        }
        if (! $download) {
            throw new RuntimeException('Node 24 was not found. Run php artisan socket-bridge:install, or set SOCKET_BRIDGE_NODE_BINARY to an existing Node 24 executable.');
        }

        return $this->download($progress);
    }

    public function validate(string $binary, ?string $exactVersion = null): string
    {
        $absolute = realpath($binary);
        if ($absolute === false || ! is_file($absolute) || ! is_executable($absolute)) {
            throw new RuntimeException('The configured Node executable does not exist or is not executable.');
        }
        $process = new Process([$absolute, '--version']);
        $process->setTimeout(10);
        try {
            $process->run();
        } catch (\Throwable $exception) {
            throw new RuntimeException('Node could not start on this platform. Install a compatible Node 24 or use Docker.', 0, $exception);
        }
        $version = trim($process->getOutput());
        if (! $process->isSuccessful() || ! preg_match('/^v24\.\d+\.\d+$/', $version)) {
            throw new RuntimeException('Socket Bridge requires Node 24. Set SOCKET_BRIDGE_NODE_BINARY to a compatible Node 24 executable.');
        }
        if ($exactVersion !== null && $version !== 'v'.$exactVersion) {
            throw new RuntimeException('The private Node cache has an unexpected version. Remove the package runtime cache and run socket-bridge:install again.');
        }

        return $absolute;
    }

    public function directory(): string
    {
        return rtrim($this->cacheDirectory, '/').'/node-'.$this->manifest->version().'-'.$this->getPlatform()->key();
    }

    private function getPlatform(): Platform
    {
        return $this->platform ?? Platform::detect();
    }

    private function download(?callable $progress): string
    {
        $artifact = $this->manifest->artifact($this->getPlatform());
        if (! extension_loaded('openssl')) {
            throw new RuntimeException('The OpenSSL PHP extension is required to download Node securely. Install Node 24 manually or enable OpenSSL.');
        }
        $this->makeDirectory($this->cacheDirectory);
        $lock = fopen($this->cacheDirectory.'/.install.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock the Socket Bridge runtime cache. Check storage directory permissions.');
        }
        @chmod($this->cacheDirectory.'/.install.lock', 0600);
        $temporary = $this->cacheDirectory.'/.download-'.bin2hex(random_bytes(8));

        try {
            if (is_file($this->directory().'/bin/node')) {
                return $this->validate($this->directory().'/bin/node', $this->manifest->version());
            }
            $this->makeDirectory($temporary);
            $archive = $temporary.'/node.tar.gz';
            $progress && $progress('Downloading official Node '.$this->manifest->version().' for '.$this->getPlatform()->key().' into private application storage.');
            $context = stream_context_create([
                'http' => ['timeout' => 180, 'follow_location' => 0, 'user_agent' => 'SocketBridge'],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $source = @fopen($artifact['url'], 'rb', false, $context);
            if ($source === false) {
                throw new RuntimeException('Cannot download Node from nodejs.org. Check network/TLS access, or install Node 24 and set SOCKET_BRIDGE_NODE_BINARY.');
            }
            $destination = @fopen($archive, 'xb');
            if ($destination === false) {
                fclose($source);
                throw new RuntimeException('Cannot write the private Node download. Check application storage permissions.');
            }
            try {
                $copied = stream_copy_to_stream($source, $destination, 150 * 1024 * 1024 + 1);
                if ($copied === false || $copied > 150 * 1024 * 1024) {
                    throw new RuntimeException('Node archive download failed or exceeded the permitted size.');
                }
            } finally {
                fclose($source);
                fclose($destination);
            }
            chmod($archive, 0600);
            self::verifyChecksum($archive, $artifact['sha256']);
            $tar = (new ExecutableFinder)->find('tar');
            if ($tar === null) {
                throw new RuntimeException('The tar executable is required to unpack Node. Install tar or configure an existing Node 24 executable.');
            }
            $unpacked = $temporary.'/unpacked';
            $this->makeDirectory($unpacked);
            $extract = new Process([$tar, '-xzf', $archive, '-C', $unpacked, '--strip-components=1']);
            $extract->setTimeout(120);
            $extract->run();
            if (! $extract->isSuccessful()) {
                throw new RuntimeException('Verified Node archive could not be extracted. Check tar/gzip and free disk space.');
            }
            chmod($unpacked.'/bin/node', 0700);
            $this->validate($unpacked.'/bin/node', $this->manifest->version());
            if (! rename($unpacked, $this->directory())) {
                throw new RuntimeException('Cannot finalize the private Node cache. Check application storage permissions.');
            }
            $progress && $progress('SHA-256 verified; private Node runtime is ready.');

            return $this->validate($this->directory().'/bin/node', $this->manifest->version());
        } finally {
            $this->removeDirectory($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function verifyChecksum(string $archive, string $expected): void
    {
        $actual = hash_file('sha256', $archive);
        if (! preg_match('/^[a-f0-9]{64}$/', $expected) || ! is_string($actual) || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('Node archive SHA-256 verification failed. The archive was rejected and will not be executed.');
        }
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create private Socket Bridge storage. Check application storage permissions.');
        }
        chmod($path, 0700);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            ($file->isDir() && ! $file->isLink()) ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($path);
    }
}
