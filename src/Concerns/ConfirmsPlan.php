<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Igne\LaravelBootUp\Boot\StepSequence;

/**
 * The standard "show the plan, then ask" gate for commands that run a step
 * sequence. The using command must declare a --yes option.
 */
trait ConfirmsPlan
{
    /**
     * Show what the command is about to do and ask to continue. Returns false
     * only when the user actively declines; auto-accept (the boot-up config
     * flag or the --yes flag) and non-interactive contexts skip the prompt.
     */
    protected function confirmPlan(StepSequence $plan, string $command, bool $autoAccept): bool
    {
        terminal()->section("What {$command} will do");
        terminal()->list($plan->summary());

        if ($autoAccept || (bool) $this->option('yes')) {
            return true;
        }

        return terminal()->confirm('Continue?', default: true);
    }
}
