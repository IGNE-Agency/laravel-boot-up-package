<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Enums\BuiltInProcess;
use Igne\LaravelBootUp\Enums\DeploymentEnvironment;
use Igne\LaravelBootUp\Enums\PackageManager;

/**
 * Everything a script generator needs to render a deployment script,
 * distilled from this package's config and the host project's bindings.
 */
final readonly class DeploymentPlan
{
    /**
     * @param  list<string>  $finalize  artisan commands run at the end of a deploy
     * @param  list<DeployTask>  $beforeMigrations
     * @param  list<DeployTask>  $afterMigrations
     * @param  list<DeployTask>  $beforeDeploy  earliest custom hook, before optimize/migrations
     * @param  list<DeployTask>  $afterDeploy  latest custom hook, after the release is finalized/live
     * @param  list<BuiltInProcess>  $restarts  long-running services to restart, in the order they run
     */
    public function __construct(
        public DeploymentEnvironment $environment,
        public bool $migrate,
        public array $finalize,
        public array $beforeMigrations,
        public array $afterMigrations,
        public bool $frontend,
        public PackageManager $packageManager,
        public array $restarts,
        public bool $zeroDowntime = true,
        public array $beforeDeploy = [],
        public array $afterDeploy = [],
    ) {}
}
