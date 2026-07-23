<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

/**
 * The logical stages app:serve groups its pipeline steps into — each gets
 * one section divider while the boot runs.
 */
enum ServeStage: string
{
    case Prepare = 'Prepare project';
    case Tools = 'Check required tools';
    case Server = 'Start server';
    case Install = 'Install dependencies';
    case Database = 'Prepare database';
    case Finalize = 'Finalize the application';
    case Custom = 'Custom steps';
}
