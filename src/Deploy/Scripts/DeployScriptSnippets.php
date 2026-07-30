<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Scripts;

use Igne\LaravelBootUp\Data\DeploymentPlan;
use Igne\LaravelBootUp\Data\DeployTask;
use Igne\LaravelBootUp\Data\Lines;

/**
 * Platform-flavoured script fragments shared by the deploy script
 * generators. Built once per generate() with the plan and the platform's
 * binaries, so the render methods receive one object instead of threading
 * the plan and binary names through every call.
 */
final readonly class DeployScriptSnippets
{
    /**
     * @param  string  $artisan  the platform's artisan invocation, e.g. '$FORGE_PHP artisan'
     * @param  string  $composer  the platform's composer binary, e.g. '$FORGE_COMPOSER'
     */
    public function __construct(
        public DeploymentPlan $plan,
        private string $artisan,
        private string $composer,
    ) {}

    /**
     * The composer install line honouring the environment's dev-dependency
     * policy.
     */
    public function composerInstall(string $flags): string
    {
        $noDev = $this->plan->environment->includeDevDependencies() ? '' : ' --no-dev';

        return "{$this->composer} install{$noDev} {$flags}";
    }

    /**
     * One artisan line in the platform's flavour.
     */
    public function artisan(string $command): string
    {
        return "{$this->artisan} {$command}";
    }

    /**
     * The task lines for one deploy phase, each preceded by its
     * description when it has one.
     *
     * @param  list<DeployTask>  $tasks
     */
    public function deployTasks(array $tasks): Lines
    {
        return Lines::make()->each($tasks, fn (Lines $script, DeployTask $task) => $script
            ->commentIf($task->description !== null, (string) $task->description)
            ->line($task->shellLine($this->artisan, $this->composer, $this->plan->packageManager->value)));
    }
}
