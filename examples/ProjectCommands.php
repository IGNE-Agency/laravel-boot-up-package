<?php

declare(strict_types=1);

namespace App\Bootstrap;

use Igne\LaravelBootstrap\Deploy\ProjectCommand;
use Igne\LaravelBootstrap\Deploy\ProvidesProjectCommands;

/**
 * Example implementation of project-specific bootstrap commands.
 *
 * Copy this file to app/Bootstrap/ProjectCommands.php and register it in
 * your AppServiceProvider:
 *
 *     $this->app->singleton(
 *         \Igne\LaravelBootstrap\Deploy\ProvidesProjectCommands::class,
 *         \App\Bootstrap\ProjectCommands::class,
 *     );
 *
 * Commands run as plain argument lists (never through a shell) and are
 * rewritten for the active server, so `php artisan ...` becomes
 * `./vendor/bin/sail artisan ...` automatically under Sail. A failing
 * command aborts the boot.
 */
final class ProjectCommands implements ProvidesProjectCommands
{
    /**
     * Runs after dependencies are installed but before migrations.
     *
     * Use this for code generation that does not depend on the database
     * schema: TypeScript route generation, Zod schemas, and the like.
     *
     * @return list<ProjectCommand>
     */
    public function beforeMigrations(): array
    {
        return [
            ProjectCommand::artisan(
                'wayfinder:generate --path=resources/js/wayfinder',
                'Generating TypeScript routes and actions with Wayfinder...',
            ),

            // ProjectCommand::packageManager(
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
     * @return list<ProjectCommand>
     */
    public function afterMigrations(): array
    {
        return [
            ProjectCommand::artisan(
                'model:typer',
                'Generating TypeScript types from Eloquent models...',
            ),

            // ProjectCommand::composer(
            //     'dump-autoload --optimize',
            //     'Optimizing the Composer autoloader...',
            // ),
        ];
    }
}
