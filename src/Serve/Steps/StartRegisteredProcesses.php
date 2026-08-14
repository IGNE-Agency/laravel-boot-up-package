<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Serve\BootCommandRegistry;
use Igne\LaravelBootUp\Serve\RegisteredWorker;
use Igne\LaravelBootUp\Serve\WorkerLauncher;

/**
 * Launches every process registered through BootCommands, after the
 * built-in workers have made their own launch decisions. Runs last in the
 * services stage so a registration can rely on tools and dependencies
 * being ready; its place in the combined stream comes from StreamOrder,
 * not from this step's pipeline position.
 */
#[Stage(ServeStage::Services)]
#[Group('workers')]
final class StartRegisteredProcesses implements Step
{
    public function __construct(
        private readonly BootCommandRegistry $registry,
        private readonly PackageManagerSelector $packageManagers,
        private readonly WorkerLauncher $launcher,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        foreach ($this->registry->suppressed() as $process) {
            terminal()->note("Registered command [{$process->name()}] skipped (BootCommands only/except).");
        }

        foreach ($this->registry->launchable() as $process) {
            // The selector only resolves (and prints its one-time note)
            // when a registration actually runs through the manager.
            $manager = $process->usesPackageManager()
                ? $this->packageManagers->selected()
                : PackageManager::default();

            $this->launcher->launch(new RegisteredWorker($process, $process->commandLine($manager)), $context);
        }

        return $next($context);
    }
}
