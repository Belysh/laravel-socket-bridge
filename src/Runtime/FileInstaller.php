<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use RuntimeException;

final class FileInstaller
{
    /** Returns false when an existing file is preserved. */
    public function writeIfAbsent(string $path, string $contents, int $permissions = 0644): bool
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create installation directory: '.$directory);
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            if (file_exists($path)) {
                return false;
            }
            throw new RuntimeException('Cannot create installation file: '.$path);
        }
        try {
            chmod($path, $permissions);
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('Could not finish writing installation file: '.$path);
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    /** Adds missing environment keys, preserving every existing value and comment. */
    public function appendEnvironment(string $path, array $values): array
    {
        $existed = is_file($path);
        $handle = @fopen($path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot update the application environment file. Check its permissions.');
        }
        if (! $existed) {
            chmod($path, 0600);
        }
        $added = [];
        try {
            $existing = stream_get_contents($handle);
            if ($existing === false) {
                throw new RuntimeException('Cannot read the application environment file.');
            }
            $lines = [];
            foreach ($values as $key => $value) {
                if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                    throw new RuntimeException('Invalid environment variable name.');
                }
                if (! preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=/m', $existing)) {
                    $lines[] = $key.'='.self::quote((string) $value);
                    $added[] = $key;
                }
            }
            if ($lines !== []) {
                fseek($handle, 0, SEEK_END);
                $append = ($existing !== '' && ! str_ends_with($existing, "\n") ? "\n" : '')."\n# Socket Bridge\n".implode("\n", $lines)."\n";
                if (fwrite($handle, $append) !== strlen($append)) {
                    throw new RuntimeException('Could not finish appending Socket Bridge environment settings.');
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $added;
    }

    public function appendIgnoreRules(string $path, array $rules): void
    {
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = preg_split('/\R/', $existing) ?: [];
        $missing = array_values(array_diff($rules, $lines));
        if ($missing !== []) {
            file_put_contents($path, ($existing !== '' && ! str_ends_with($existing, "\n") ? "\n" : '')."\n# Socket Bridge local runtime credentials\n".implode("\n", $missing)."\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** Explicit install action: select this package's broadcaster, leaving all other keys intact. */
    public function selectBroadcaster(string $path): void
    {
        $this->selectSetting($path, 'BROADCAST_CONNECTION', 'socketio');
    }

    public function selectMode(string $path, string $mode): void
    {
        if (! in_array($mode, ['native', 'docker'], true)) {
            throw new RuntimeException('Socket Bridge installation mode must be native or docker.');
        }
        $this->selectSetting($path, 'SOCKET_BRIDGE_MODE', $mode);
    }

    /** Update explicitly selected keys; values remain valid dotenv with secrets escaped. */
    public function setEnvironment(string $path, array $values, array $remove = []): void
    {
        foreach ([...array_keys($values), ...$remove] as $key) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/D', $key)) {
                throw new RuntimeException('Invalid environment variable name.');
            }
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot update the application environment file. Check its permissions.');
        }
        try {
            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new RuntimeException('Cannot read the application environment file.');
            }
            $updated = $contents;
            foreach ($values as $key => $value) {
                $pattern = '/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=[^\r\n]*/m';
                $line = $key.'='.self::quote((string) $value);
                $updated = preg_match($pattern, $updated)
                    ? preg_replace_callback($pattern, static fn (): string => $line, $updated)
                    : $updated.(str_ends_with($updated, "\n") || $updated === '' ? '' : "\n").$line."\n";
            }
            foreach ($remove as $key) {
                if (! array_key_exists($key, $values)) {
                    $updated = preg_replace('/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=[^\r\n]*(?:\r?\n|$)/m', '', $updated);
                }
            }
            if ($updated !== $contents) {
                rewind($handle);
                if (fwrite($handle, $updated) !== strlen($updated) || ! ftruncate($handle, strlen($updated))) {
                    throw new RuntimeException('Could not finish writing environment settings; restore the private configuration backup before retrying.');
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    /** Configuration backups contain credentials, so both directory and files are private. */
    public function backup(array $paths, string $directory): string
    {
        $destination = rtrim($directory, '/').'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        if (! @mkdir($destination, 0700, true)) {
            throw new RuntimeException('Cannot create the private Socket Bridge configuration backup.');
        }
        foreach ($paths as $name => $path) {
            if (is_file($path)) {
                $contents = file_get_contents($path);
                if ($contents === false) {
                    throw new RuntimeException('Cannot read a configuration file for backup.');
                }
                $this->writeIfAbsent($destination.'/'.basename(is_string($name) ? $name : $path), $contents, 0600);
            }
        }

        return $destination;
    }

    private function selectSetting(string $path, string $key, string $value): void
    {
        $handle = @fopen($path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot configure '.$key.' in the application environment.');
        }
        try {
            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new RuntimeException('Cannot read the application environment.');
            }
            $pattern = '/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=[^\r\n]*/m';
            $updated = preg_match($pattern, $contents)
                ? preg_replace_callback($pattern, static fn (): string => $key.'='.$value, $contents)
                : $contents.(str_ends_with($contents, "\n") || $contents === '' ? '' : "\n").$key.'='.$value."\n";
            if ($updated !== $contents) {
                rewind($handle);
                if (fwrite($handle, $updated) !== strlen($updated) || ! ftruncate($handle, strlen($updated))) {
                    throw new RuntimeException('Could not write '.$key.'. Check application environment file permissions.');
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"', '$', "\r", "\n"], ['\\\\', '\\"', '\\$', '\\r', '\\n'], $value).'"';
    }
}
