<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Enums\BuiltInProcess;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Illuminate\Foundation\DevCommand;
use Illuminate\Foundation\DevCommands;

/**
 * Folds DevProcessDecisions' verdicts into Laravel's dev-process registry:
 * registers the processes this run needs, rewrites each command for the
 * server that booted (under Sail they run in the container rather than on
 * the host) and filters out the ones the decisions gated off.
 */
final class DevProcessRegistrar
{
    /**
     * only() with no names means "no filter", so a run where every process is
     * gated off needs a name that matches nothing to say so.
     */
    private const string MATCHES_NOTHING = '__boot-up:none__';

    public function __construct(
        private readonly DevProcessDecisions $decisions,
        private readonly CommandRewriter $rewriter,
    ) {}

    /**
     * The names that will run, in the order their tabs will appear.
     *
     * @return list<string>
     */
    public function preview(BootContext $context): array
    {
        $running = [];

        foreach ($this->decisions->for($context) as $process) {
            if ($process->runs) {
                $running[$process->name] = true;
            }
        }

        $ordered = [];

        // Registering an existing name replaces it in its own slot, so the
        // names already in the registry -- Laravel's defaults and whatever
        // the application and its packages registered -- keep their position.
        foreach (array_column(DevCommands::commands(), 'name') as $name) {
            if (! isset($running[$name]) && \in_array($name, BuiltInProcess::names(), true)) {
                continue;
            }

            $ordered[] = $name;
            unset($running[$name]);
        }

        // The rest are boot-up's own, which register for the first time and
        // are therefore appended, in the order the decisions are made.
        return [...$ordered, ...array_keys($running)];
    }

    /**
     * Register boot-up's processes and filter out the ones this run does not
     * need.
     */
    public function apply(BootContext $context): void
    {
        $suppressed = [];

        foreach ($this->decisions->for($context) as $process) {
            if (! $process->runs) {
                $suppressed[] = $process->name;

                if ($process->skipReason !== null) {
                    terminal()->note($process->skipReason);
                }

                continue;
            }

            if ($process->command === null || $this->isClaimed($process->name)) {
                continue;
            }

            DevCommands::register(
                $this->rewriter->rewriteFor($context, $process->command)->toString(),
                $process->name,
            );
        }

        $this->suppress($suppressed);
    }

    /**
     * Whether someone else already owns this name with more authority than
     * boot-up has.
     */
    private function isClaimed(string $name): bool
    {
        $command = collect(DevCommands::commands())
            ->first(fn (array $command): bool => $command['name'] === $name);

        return $command !== null && $this->outranksBootUp($name, $command['priority']);
    }

    /**
     * The application always wins — someone who writes a registration into
     * their own provider means it.
     *
     * A package only wins for `server`: Octane registers itself there, and
     * `octane:start --watch` is the better server for a project that installed
     * it. Everywhere else boot-up replaces a package's registration, because
     * it is the only party that knows the command has to run inside Sail's
     * containers rather than on the host.
     */
    private function outranksBootUp(string $name, int $priority): bool
    {
        return $name === BuiltInProcess::Server->value
            ? $priority > DevCommand::PRIORITY_DEFAULT
            : $priority > DevCommand::PRIORITY_VENDOR;
    }

    /**
     * Take the gated-off processes out of the run.
     *
     * only() and except() both overwrite the whole filter and neither can be
     * read back, so calling except() here would discard what another package
     * asked for — Horizon excludes the queue worker this way. Filtering to the
     * names that survive instead keeps those earlier decisions, because
     * commands() has already applied them.
     *
     * @param  list<string>  $suppressed
     */
    private function suppress(array $suppressed): void
    {
        if ($suppressed === []) {
            return;
        }

        $remaining = array_values(array_diff(
            array_column(DevCommands::commands(), 'name'),
            $suppressed,
        ));

        DevCommands::only(...($remaining === [] ? [self::MATCHES_NOTHING] : $remaining));
    }
}
