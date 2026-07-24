<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Data\DeployTask;

/**
 * Implement this in the host application and bind it as a singleton in a
 * service provider to hook project-specific commands into the deploy flow:
 *
 *     $this->app->singleton(ProvidesDeployTasks::class, MyProjectCommands::class);
 *
 * Four phases, in execution order. Return an empty array for any phase you
 * do not use. The generated deploy scripts (Forge, Fortrabbit) and CI run
 * every phase; the local app:serve / app:deploy pipeline runs the migration
 * phases by default (add the deploy phases to boot-up.serve_steps /
 * deploy_steps to run those locally too). A failing command aborts the deploy.
 */
interface ProvidesDeployTasks
{
    /**
     * Before the framework is optimized and before migrations — the earliest
     * hook, once dependencies are installed (e.g. schema-independent codegen).
     *
     * @return list<DeployTask>
     */
    public function beforeDeploy(): array;

    /**
     * After dependencies are installed but before migrations.
     *
     * @return list<DeployTask>
     */
    public function beforeMigrations(): array;

    /**
     * After migrations but before the application is finalized.
     *
     * @return list<DeployTask>
     */
    public function afterMigrations(): array;

    /**
     * After the application is finalized and live — the latest hook (e.g.
     * cache warming that needs the activated release).
     *
     * @return list<DeployTask>
     */
    public function afterDeploy(): array;
}
