<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Deploy\Scripts;

use Igne\LaravelBootstrap\Deploy\ProjectCommand;
use Igne\LaravelBootstrap\Frontend\PackageManager;

/**
 * Everything a script generator needs to render a deployment script,
 * distilled from this package's config and the host project's bindings.
 */
final readonly class DeploymentPlan
{
    /**
     * @param  list<string>  $finalize  artisan commands run at the end of a deploy
     * @param  list<ProjectCommand>  $beforeMigrations
     * @param  list<ProjectCommand>  $afterMigrations
     */
    public function __construct(
        public DeploymentEnvironment $environment,
        public bool $migrate,
        public array $finalize,
        public array $beforeMigrations,
        public array $afterMigrations,
        public bool $frontend,
        public PackageManager $packageManager,
        public bool $restartQueues,
        public bool $zeroDowntime = true,
    ) {}
}
