<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Concerns;

use Closure;
use Illuminate\Foundation\DevCommand;
use Illuminate\Foundation\DevCommands;

/**
 * Helpers for tests that assert against Laravel's dev process registry.
 *
 * DevCommands infers a registration's priority from the backtrace: a caller
 * below base_path('vendor') is a package, anything else is the application.
 * Under Testbench that directory is a symlink to the real vendor, so every
 * registration — including one standing in for Horizon or Octane — resolves
 * to PRIORITY_USERLAND. Tests that care about priority therefore seed the
 * registry directly instead of calling DevCommands::artisan() and hoping.
 */
trait InteractsWithDevCommands
{
    /**
     * Place a registration in the registry at an explicit priority, as if it
     * came from the framework's own defaults or from an installed package.
     */
    protected function seedDevCommand(
        string $command,
        ?string $name = null,
        int $priority = DevCommand::PRIORITY_VENDOR,
        ?string $color = null,
    ): DevCommand {
        $source = ['file' => __FILE__, 'line' => __LINE__, 'class' => self::class];

        $devCommand = new DevCommand($command, $source, $name, $priority);

        if ($color !== null) {
            $devCommand->color($color);
        }

        Closure::bind(function () use ($devCommand): void {
            self::$commands[$devCommand->name()] = $devCommand;
        }, null, DevCommands::class)();

        return $devCommand;
    }

    /**
     * The names the dev command would run, in order, after filters are applied.
     *
     * @return list<string>
     */
    protected function devCommandNames(): array
    {
        return array_column(DevCommands::commands(), 'name');
    }

    /**
     * A single registration as the dev command would see it, or null when it is
     * absent or filtered out.
     *
     * @return array{command: string, name: string, color: string|null, source: array<string, mixed>, priority: int}|null
     */
    protected function devCommand(string $name): ?array
    {
        foreach (DevCommands::commands() as $command) {
            if ($command['name'] === $name) {
                return $command;
            }
        }

        return null;
    }
}
