<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Database\DatabaseProbe;

/**
 * Asks the configured database for a connection and says so in its exit
 * code: 0 reachable, 1 not, with the driver's own message as the body.
 *
 * It exists for the servers whose database the host cannot reach — under
 * Sail the boot runs `./vendor/bin/sail artisan app:db-ping`, so the check
 * happens on the container's network while being the same DatabaseProbe the
 * host-side path calls directly. Doing it in-container any other way would
 * mean bootstrapping the framework from a `php -r` one-liner, or borrowing
 * a command that reads schema and therefore answers a different question.
 *
 * Hidden because nobody needs to type it, and plain-spoken on purpose: the
 * output is read by DatabaseFailureDetector, so no prompts, no decoration.
 */
final class DbPingCommand extends BootUpCommand
{
    /**
     * Named once: VerifyDatabaseConnection builds the command line that
     * invokes this, through the active server's rewrites.
     */
    public const string NAME = 'app:db-ping';

    protected $signature = self::NAME;

    protected $description = 'Check that the configured database accepts connections (used by app:up)';

    protected $hidden = true;

    public function handle(DatabaseProbe $probe): int
    {
        $failure = $probe->attempt();

        if ($failure === null) {
            $this->line("Database connection established [{$probe->connectionName()}].");

            return self::SUCCESS;
        }

        $this->line($failure);

        return self::FAILURE;
    }
}
