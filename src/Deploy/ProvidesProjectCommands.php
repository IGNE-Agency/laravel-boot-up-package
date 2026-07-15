<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy;

/**
 * Implement this in the host application and bind it as a singleton in a
 * service provider to hook project-specific commands into the deploy flow:
 *
 *     $this->app->singleton(ProvidesProjectCommands::class, MyProjectCommands::class);
 */
interface ProvidesProjectCommands
{
    /**
     * @return list<ProjectCommand>
     */
    public function beforeMigrations(): array;

    /**
     * @return list<ProjectCommand>
     */
    public function afterMigrations(): array;
}
