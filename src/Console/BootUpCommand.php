<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Concerns\AnnouncesRun;
use Igne\LaravelBootUp\Concerns\ConfirmsPlan;
use Igne\LaravelBootUp\Concerns\GuardsAgainstFailures;
use Igne\LaravelBootUp\Concerns\PromptsForChoice;
use Igne\LaravelBootUp\Concerns\RequiresUnix;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Thin composition root for every boot-up command: the concerns carry the
 * shared behavior, and execute() wraps the framework's own run so
 * subclasses write a plain handle() with container-injected parameters —
 * exactly like any Laravel command, minus per-command error plumbing.
 */
abstract class BootUpCommand extends Command
{
    use AnnouncesRun;
    use ConfirmsPlan;
    use GuardsAgainstFailures;
    use PromptsForChoice;
    use RequiresUnix;

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! $this->runsOnThisPlatform()) {
            return self::FAILURE;
        }

        // parent::execute() keeps the Isolatable mutex, ManuallyFailedException
        // handling and container method-injection of handle().
        return $this->guardAgainstFailures(fn (): int => parent::execute($input, $output));
    }
}
