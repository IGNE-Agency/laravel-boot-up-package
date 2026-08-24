<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Console\Fixtures;

use Igne\LaravelBootUp\Console\DevCommand;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\NodePackageManager;

/**
 * Stands in for the real dev command to exercise the foreground path.
 *
 * A test has no terminal for the multiplexer and cannot start one, so this
 * records what was handed over instead of running it — which is the actual
 * contract between boot-up and Laravel.
 */
final class CapturingDevCommand extends DevCommand
{
    /** @var array<int, array<string, mixed>> */
    public array $handedOver = [];

    public int $handoffs = 0;

    /**
     * What the active-server record said while the session was running —
     * the only moment a caller can see it, since a session app:up owns
     * clears the record as soon as this returns.
     */
    public ?ActiveServerRecord $activeAtHandoff = null;

    /** What the session hands back; a test can make it a failure. */
    public int $exitCode = 0;

    protected function delegateToFramework(NodePackageManager $packageManager): int
    {
        $this->handedOver = DevCommands::commands();
        $this->activeAtHandoff = $this->laravel->make(ActiveServerStore::class)->current();
        $this->handoffs++;

        return $this->exitCode;
    }
}
