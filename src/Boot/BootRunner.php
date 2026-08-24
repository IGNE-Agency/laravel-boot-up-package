<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

use Closure;
use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\ServerSelector;
use Illuminate\Pipeline\Pipeline;
use LogicException;

/**
 * The boot lifecycle in one place: prepare() guards and plans, run()
 * executes the sealed begin → trap → pipeline → finish sequence and hands
 * back the context, tearDown() settles everything the boot started, and
 * fail() settles the progress bar when the command's failure funnel fires.
 * DevCommand maps flags, runs the confirm gate, and delegates here.
 *
 * What runs AFTER the boot is not this class's business: the dev processes
 * belong to Laravel's dev command, and app:deploy has none.
 */
final class BootRunner
{
    private ?BootContext $context = null;

    private ?StepSequence $plan = null;

    private bool $tearingDown = false;

    /**
     * Set once the dev processes own the terminal: from then on Ctrl+C is
     * theirs to handle, and teardown happens when they exit rather than in
     * the signal handler.
     */
    private bool $handedOff = false;

    public function __construct(
        private readonly ServerSelector $selector,
        private readonly SetupConfig $config,
        private readonly ShutdownRunner $shutdown,
        private readonly ActiveServerStore $store,
        private readonly BootProcessProbe $probe,
        private readonly ProcessReaper $reaper,
        private readonly Pipeline $pipeline,
        private readonly StageReporter $reporter,
        private readonly DevProcessRegistrar $registrar,
    ) {}

    /**
     * Guard against a second instance, prune dead ledger entries, pick the
     * server (may prompt) and produce the plan. Returns null — with the
     * warning already printed — when another boot owns this project.
     */
    public function prepare(BootOptions $options, ?string $server): ?StepSequence
    {
        if ($this->anotherServeIsRunning()) {
            terminal()->warning('The application is already being served for this project. Aborting.');

            return null;
        }

        $this->reaper->prune();

        terminal()->intro('Booting the application...');

        $this->context = new BootContext($options, $this->selector->select($server));

        return $this->plan = StepSequence::for(
            $this->config->steps,
            $options,
            $this->context->server?->label(),
            $this->registrar->preview($this->context),
        );
    }

    /**
     * The sealed lifecycle. $trapUsing REGISTERS a signal handler —
     * fn (array $signals, Closure $handler) — rather than being one: the
     * handler must close over runner-owned state (the reporter, the live
     * stream, the teardown guard).
     *
     * begin() and the pipeline are deliberately not exposed separately.
     * The trap must be registered between them: before begin() the
     * progress bar's own SIGINT handler (installed by Progress::start())
     * would replace it, and after the pipeline starts an early Ctrl+C
     * would orphan the write-ahead ActiveServerRecord.
     *
     * @param  Closure(list<int>, Closure): void  $trapUsing
     */
    public function run(Closure $trapUsing): BootContext
    {
        $context = $this->context;
        $plan = $this->plan;

        if ($context === null || $plan === null) {
            throw new LogicException('BootRunner::run() requires a prepare() that produced a plan.');
        }

        $pipes = $this->reporter->begin($plan);

        $trapUsing([SIGINT, SIGTERM], function (): void {
            // Once the dev processes are running, the multiplexer handles
            // Ctrl+C and shuts its children down in order; tearing down from
            // here would race it and print prompts over a live UI.
            if ($this->handedOff) {
                return;
            }

            // A second signal while teardown is in flight would re-enter
            // here and exit(0) before the first pass finishes its cleanup.
            if ($this->tearingDown) {
                return;
            }

            $this->tearingDown = true;

            $this->reporter->interrupt();
            $this->shutdown->run();
            exit(0);
        });

        $this->pipeline->send($context)->through($pipes)->thenReturn();

        $this->reporter->finish();

        return $context;
    }

    /**
     * Hand Ctrl+C to the dev processes. Called once the boot is done and
     * before the terminal UI takes over the foreground.
     */
    public function handOff(): void
    {
        $this->handedOff = true;
    }

    /**
     * Settle everything the boot started. Idempotent, and a friendly no-op
     * when app:down from another terminal already did the work.
     */
    public function tearDown(): void
    {
        $this->tearingDown = true;

        $this->shutdown->run();
    }

    /**
     * Settle the progress bar when the command's failure funnel fires —
     * reached through the SAME instance handle() received, because the
     * funnel runs outside handle() where re-resolving would produce a
     * fresh runner with a fresh, unbound reporter.
     */
    public function fail(): void
    {
        $this->reporter->fail();
    }

    private function anotherServeIsRunning(): bool
    {
        $active = $this->store->current();

        if ($active === null || $active->servePid === getmypid()) {
            return false;
        }

        return $this->probe->isServing($active->servePid);
    }
}
