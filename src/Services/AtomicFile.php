<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services;

use Igne\LaravelBootUp\Exceptions\FileException;

/**
 * Crash-safe file primitives: content goes to a temporary sibling first
 * and is renamed into place, so readers never observe a half-written file.
 */
final class AtomicFile
{
    /**
     * What Laravel puts in its own storage state directories, and for the same
     * reason: nothing written there belongs in version control, and the
     * framework's storage/framework/.gitignore lists filenames rather than a
     * wildcard, so a new subdirectory of it is not covered.
     */
    private const string DIRECTORY_GITIGNORE = "*\n!.gitignore\n";

    /**
     * $permissions applies to the file before it is moved into place, so it
     * is never briefly readable at the umask default — for content that
     * should not be world-readable at any point.
     */
    public static function write(string $path, string $content, ?int $permissions = null): void
    {
        self::ensureDirectory(\dirname($path));

        $suffix = bin2hex(random_bytes(4));
        $temporary = "{$path}.tmp-{$suffix}";

        // A silent failure here loses state the boot depends on: a PID that
        // never reached the ledger is a process nothing can stop later.
        if (file_put_contents($temporary, $content) === false) {
            throw FileException::writeFailed($temporary);
        }

        if ($permissions !== null) {
            chmod($temporary, $permissions);
        }

        if (! rename($temporary, $path)) {
            @unlink($temporary);

            throw FileException::moveFailed($temporary, $path);
        }
    }

    /**
     * Directories this package creates are for machine-local state, so each
     * one is born ignored. Only on creation: a directory that already exists
     * is the project's to organise.
     */
    private static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        mkdir($directory, 0755, true);

        @file_put_contents("{$directory}/.gitignore", self::DIRECTORY_GITIGNORE);
    }

    public static function delete(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
