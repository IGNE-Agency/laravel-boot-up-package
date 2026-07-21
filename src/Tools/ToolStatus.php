<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools;

/**
 * What ToolManager::ensure() concluded about one tool. Satisfied is the
 * quiet path — it prints nothing and is bundled into one summary after all
 * checks; every other status also printed its own line during the run.
 */
enum ToolStatus: string
{
    case Satisfied = 'satisfied';
    case Installed = 'installed';
    case Updated = 'updated';
    case SkippedSelfUpdating = 'self-updating';
    case Unverified = 'unverified';
}
