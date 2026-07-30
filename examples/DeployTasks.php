<?php

declare(strict_types=1);

namespace App\BootUp;

use Igne\LaravelBootUp\Contracts\ProvidesDeployTasks;
use Igne\LaravelBootUp\Data\DeployTask;

/**
 * Example implementation of project-specific boot commands.
 *
 * Copy this file to app/BootUp/DeployTasks.php and register it in
 * your AppServiceProvider:
 *
 *     $this->app->singleton(
 *         \Igne\LaravelBootUp\Contracts\ProvidesDeployTasks::class,
 *         \App\BootUp\DeployTasks::class,
 *     );
 *
 * Commands run as plain argument lists (never through a shell) and are
 * rewritten for the active server, so `php artisan ...` becomes
 * `./vendor/bin/sail artisan ...` automatically under Sail. A failing
 * command aborts the boot.
 */
final class DeployTasks implements ProvidesDeployTasks
{
    /**
     * Runs first, once dependencies are installed and before the framework
     * is optimized or migrated. Use this for the earliest, schema-independent
     * work. Return [] if you have none.
     *
     * @return list<DeployTask>
     */
    public function beforeDeploy(): array
    {
        return [];
    }

    /**
     * Runs after dependencies are installed but before migrations.
     *
     * Use this for code generation that does not depend on the database
     * schema: TypeScript route generation, Zod schemas, and the like.
     *
     * @return list<DeployTask>
     */
    public function beforeMigrations(): array
    {
        return [
            DeployTask::artisan(
                'wayfinder:generate --path=resources/js/wayfinder',
                'Generating TypeScript routes and actions with Wayfinder...',
            ),

            // DeployTask::packageManager(
            //     'run zodgen',
            //     'Generating Zod schemas from resources...',
            // ),
        ];
    }

    /**
     * Runs after migrations but before caching and the queue worker.
     *
     * Use this for anything that needs the up-to-date database schema:
     * model-based type generation, cache warming, data processing.
     *
     * @return list<DeployTask>
     */
    public function afterMigrations(): array
    {
        return [
            DeployTask::artisan(
                'model:typer',
                'Generating TypeScript types from Eloquent models...',
            ),

            // DeployTask::composer(
            //     'dump-autoload --optimize',
            //     'Optimizing the Composer autoloader...',
            // ),
        ];
    }

    /**
     * Runs last, after the application is finalized and the release is live.
     *
     * Use this for anything that should happen only once the new release is
     * serving traffic: cache warming, health pings, notifications. Return []
     * if you have none.
     *
     * @return list<DeployTask>
     */
    public function afterDeploy(): array
    {
        return [];
    }
}
