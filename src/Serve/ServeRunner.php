<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Closure;
use Igne\LaravelBootUp\Config\ServeConfig;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Process\OutputMultiplexer;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\ServerSelector;
use Illuminate\Pipeline\Pipeline;
use LogicException;

/**
 * The serve lifecycle in one place: prepare() guards and plans, run()
 * executes the sealed begin → trap → pipeline → finish → stream →
 * shutdown sequence, fail() settles the progress bar when the command's
 * failure funnel fires. ServeCommand maps flags, runs the confirm gate,
 * and delegates here.
 */
final class ServeRunner
{
    private ?ServeContext $context = null;

    private ?StepSequence $plan = null;

    private ?OutputMultiplexer $streaming = null;

    private bool $tearingDown = false;

    public function __construct(
        private readonly ServerSelector $selector,
        private readonly ServeConfig $config,
        private readonly ShutdownRunner $shutdown,
        private readonly ActiveServerStore $store,
        private readonly ServeProcessProbe $probe,
        private readonly ProcessReaper $reaper,
        private readonly Pipeline $pipeline,
        private readonly StageReporter $reporter,
        private readonly CombinedRunPlan $combined,
        private readonly OutputMultiplexer $multiplexer,
        private readonly StreamOrder $order,
        private readonly BootCommandRegistry $registry,
    ) {}

    /**
     * Guard against a second instance, prune dead ledger entries, pick the
     * server (may prompt) and produce the plan. Returns null — with the
     * warning already printed — when another app:serve owns this project.
     */
    public function prepare(ServeOptions $options, ?string $server): ?StepSequence
    {
        if ($this->anotherServeIsRunning()) {
            terminal()->warning('Another app:serve is already running for this project. Aborting.');

            return null;
        }

        $this->reaper->prune();

        terminal()->intro('Booting the application...');

        $this->context = new ServeContext($options, $this->selector->select($server));

        return $this->plan = StepSequence::for(
            $this->config->steps,
            $options,
            $this->context->server?->label(),
            $this->registry->summaryLabels(),
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
    public function run(Closure $trapUsing): int
    {
        $context = $this->context;
        $plan = $this->plan;

        if ($context === null || $plan === null) {
            throw new LogicException('ServeRunner::run() requires a prepare() that produced a plan.');
        }

        $pipes = $this->reporter->begin($plan);

        $trapUsing([SIGINT, SIGTERM], function (): void {
            // A second signal while teardown is in flight would re-enter
            // here and exit(0) before the first pass finishes its cleanup.
            if ($this->tearingDown) {
                return;
            }

            $this->tearingDown = true;

            // Mid-stream Ctrl+C: end the multiplexer loop first so the
            // teardown prompts render on a quiet terminal. The children
            // already received the process group's SIGINT.
            $this->streaming?->stop();
            $this->reporter->interrupt();
            $this->shutdown->run();
            exit(0);
        });

        $this->pipeline->send($context)->through($pipes)->thenReturn();

        $this->reporter->finish();

        if ($context->options->follow && $this->combined->hasProcesses()) {
            return $this->streamCombinedOutput();
        }

        terminal()->outro('Application ready.');

        return 0;
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

    /**
     * The composer-run-dev experience: after the boot, stay in the
     * foreground and interleave every combined worker's output here. The
     * loop ends on Ctrl+C (the trap stops it before tearing down) or when
     * every worker has exited — either way one shared shutdown path runs,
     * a friendly no-op when app:down from another terminal already did.
     */
    private function streamCombinedOutput(): int
    {
        terminal()->outro('Application ready.');
        terminal()->info('Streaming service output below — press Ctrl+C to stop everything.');

        $this->streaming = $this->multiplexer;
        $this->multiplexer->stream($this->order->apply($this->combined));
        $this->streaming = null;

        $this->shutdown->run();

        return 0;
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
