<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

use Igne\LaravelBootUp\Tools\ToolManager;

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

    /**
     * The summary-line suffix for this status; Satisfied is the quiet path
     * and adds nothing.
     */
    public function label(): string
    {
        return match ($this) {
            self::Satisfied => '',
            self::Installed => 'installed',
            self::Updated => 'updated',
            self::SkippedSelfUpdating => 'manages its own updates',
            self::Unverified => 'could not be verified (see warning above)',
        };
    }
}
