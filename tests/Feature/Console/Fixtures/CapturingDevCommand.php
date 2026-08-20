<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Console\Fixtures;

use Igne\LaravelBootUp\Console\DevCommand;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\NodePackageManager;

/**
 * Stands in for the real dev command to exercise the foreground path.
 *
 * A test has no terminal for the multiplexer and cannot start one, so this
 * claims to have a TTY and records what the boot handed over instead of
 * running it — which is the actual contract between boot-up and Laravel.
 */
final class CapturingDevCommand extends DevCommand
{
    /** @var array<int, array<string, mixed>> */
    public array $handedOver = [];

    public int $handoffs = 0;

    protected function stdoutIsInteractive(): bool
    {
        return true;
    }

    protected function delegateToFramework(NodePackageManager $packageManager): int
    {
        $this->handedOver = DevCommands::commands();
        $this->handoffs++;

        return 0;
    }
}
