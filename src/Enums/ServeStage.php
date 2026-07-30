<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

/**
 * The logical stages app:serve groups its pipeline steps into — each gets
 * one section divider while the boot runs.
 */
enum ServeStage: string
{
    case Prepare = 'prepare';
    case Tools = 'tools';
    case Server = 'server';
    case Install = 'install';
    case Database = 'database';
    case Cache = 'cache';
    case Finalize = 'finalize';
    case Services = 'services';
    case Assets = 'assets';
    case Announce = 'announce';
    case Custom = 'custom';

    /**
     * The section-divider wording for this stage.
     */
    public function label(): string
    {
        return match ($this) {
            self::Prepare => 'Prepare project',
            self::Tools => 'Check required tools',
            self::Server => 'Start server',
            self::Install => 'Install dependencies',
            self::Database => 'Prepare database',
            self::Cache => 'Cache framework files',
            self::Finalize => 'Finalize the application',
            self::Services => 'Start services',
            self::Assets => 'Build or watch assets',
            self::Announce => 'Announce the application',
            self::Custom => 'Custom steps',
        };
    }
}
