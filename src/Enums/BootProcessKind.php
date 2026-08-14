<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

use Igne\LaravelBootUp\Data\CommandLine;

/**
 * How a registered boot command turns into a runnable command line. Kept as
 * a kind (rather than resolving at registration time) so the package
 * manager is looked up lazily at launch — registration happens during
 * provider boot, before any selection output belongs on screen.
 */
enum BootProcessKind
{
    case Shell;
    case Artisan;
    case PackageManager;
    case PackageManagerExec;

    public function commandLine(string $command, PackageManager $packageManager): CommandLine
    {
        return CommandLine::make(match ($this) {
            self::Shell => $command,
            self::Artisan => "php artisan {$command}",
            self::PackageManager => "{$packageManager->binary()} {$command}",
            self::PackageManagerExec => implode(' ', [...$packageManager->execCommand(), $command]),
        });
    }

    /**
     * Whether building the command line needs the project's package
     * manager resolved — Shell and Artisan commands run as written.
     */
    public function usesPackageManager(): bool
    {
        return match ($this) {
            self::PackageManager, self::PackageManagerExec => true,
            self::Shell, self::Artisan => false,
        };
    }

    /**
     * The name a registration gets when none is given: the command's first
     * token, except that a package-manager `run <script>` names itself
     * after the script.
     */
    public function defaultName(string $command): string
    {
        $tokens = preg_split('/\s+/', trim($command)) ?: [];

        if ($this === self::PackageManager && ($tokens[0] ?? null) === 'run' && isset($tokens[1])) {
            return $tokens[1];
        }

        return $tokens[0] ?? $command;
    }
}
