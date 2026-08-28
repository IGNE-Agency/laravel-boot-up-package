<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Igne\LaravelBootUp\Facades\Platform;

/**
 * The native-Windows guard. Commands that manage OS processes refuse to
 * run outside a Unix-like environment; pure file generators run anywhere.
 *
 * @phpstan-require-extends \Illuminate\Console\Command
 */
trait RequiresUnix
{
    /**
     * Commands that manage OS processes override this to return true.
     */
    protected function requiresUnix(): bool
    {
        return false;
    }

    protected function runsOnThisPlatform(): bool
    {
        if ($this->requiresUnix() && Platform::isWindows()) {
            terminal()->error("{$this->getName()} is not supported on native Windows. Run it inside WSL2.");

            return false;
        }

        return true;
    }
}
