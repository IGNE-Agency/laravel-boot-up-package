<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services;

/**
 * Crash-safe file primitives: content goes to a temporary sibling first
 * and is renamed into place, so readers never observe a half-written file.
 */
final class AtomicFile
{
    public static function write(string $path, string $content): void
    {
        self::ensureDirectory(\dirname($path));

        $suffix = bin2hex(random_bytes(4));
        $temporary = "{$path}.tmp-{$suffix}";

        file_put_contents($temporary, $content);
        rename($temporary, $path);
    }

    private static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    public static function delete(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
