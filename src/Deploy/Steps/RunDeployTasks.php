<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\DescribesProgress;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Deploy\DeployTaskRunner;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Enums\DeployPhase;
use Igne\LaravelBootUp\Exceptions\ConfigException;

/**
 * Runs the host's project commands for one phase. Placed in the pipeline with
 * a phase suffix Laravel passes as the $phase argument, e.g.
 * `RunDeployTasks::class.':before'` (before migrations) or `':after'`.
 * The deploy phases `':before-deploy'` / `':after-deploy'` are also accepted
 * for pipelines that want the full four-phase model locally.
 */
#[Stage(BootStage::Database)]
#[Group('deploy-tasks')]
final class RunDeployTasks implements DescribesProgress, Step
{
    public function __construct(private readonly DeployTaskRunner $runner) {}

    /**
     * $phase stays a string because the value arrives spread from the
     * pipeline entry's ':phase' suffix — the enum boundary is right here.
     */
    public function handle(BootContext $context, Closure $next, string $phase = 'before'): mixed
    {
        $parsed = DeployPhase::tryFrom($phase)
            ?? throw ConfigException::invalidEnumValue('boot-up.setup.steps', $phase, DeployPhase::class);

        $this->runner->run($parsed, $context);

        return $next($context);
    }

    public static function progressLabel(BootOptions $options, array $parameters): string
    {
        $configured = $parameters[0] ?? DeployPhase::Before->value;
        $phase = DeployPhase::tryFrom($configured);

        // A typo would otherwise read as a real phase in the plan the user is
        // about to confirm, seconds before the boot rejects it.
        return $phase === null
            ? "Running project commands (unknown phase [{$configured}])"
            : "Running project commands ({$phase->label()})";
    }
}
