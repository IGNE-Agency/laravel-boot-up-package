<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Config\ServeConfig;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Process\OutputMultiplexer;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Serve\CombinedRunPlan;
use Igne\LaravelBootUp\Serve\ServeProcessProbe;
use Igne\LaravelBootUp\Serve\ShutdownRunner;
use Igne\LaravelBootUp\Serve\StageReporter;
use Igne\LaravelBootUp\Serve\StepSequence;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\ServerSelector;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Pipeline\Pipeline;

final class ServeCommand extends BootUpCommand implements Isolatable
{
    private ?StageReporter $reporter = null;

    private ?OutputMultiplexer $streaming = null;

    private bool $tearingDown = false;

    protected $signature = 'app:serve {server? : The development server to use (herd, sail, laravel, or any driver registered in boot-up.server.drivers)}
        {--s|seed : Seed the database after migrating}
        {--no-migrate : Skip running pending migrations}
        {--fresh : Drop all tables and re-run every migration (asks first)}
        {--u|update : Update dependencies instead of installing}
        {--without-queue : Do not start a queue worker}
        {--without-assets : Skip frontend dependencies and assets}
        {--d|detach : Do not stream combined worker output; run everything detached}
        {--y|yes : Run without the confirmation prompt}';

    protected $description = 'Boot everything the application needs and serve it locally';

    protected function requiresUnix(): bool
    {
        return true;
    }

    public function handle(
        ServerSelector $selector,
        ServeConfig $config,
        ShutdownRunner $shutdown,
        ActiveServerStore $store,
        ServeProcessProbe $probe,
        ProcessReaper $reaper,
        Pipeline $pipeline,
        StageReporter $reporter,
        CombinedRunPlan $combined,
        OutputMultiplexer $multiplexer,
    ): int {
        if ($this->anotherServeIsRunning($store, $probe)) {
            terminal()->warning('Another app:serve is already running for this project. Aborting.');

            return self::FAILURE;
        }

        $reaper->prune();

        $this->announce('Booting the application...');

        $context = new ServeContext($this->serveOptions(), $selector->select($this->argument('server')));

        $plan = StepSequence::for($config->steps, $context->options, $context->server?->label());

        if (! $this->confirmPlan($plan, 'app:serve', $config->autoAccept)) {
            return $this->skip('Aborted — nothing was changed.');
        }

        $this->reporter = $reporter;
        $pipes = $reporter->begin($plan);

        $this->registerShutdownTrap($shutdown, $reporter);

        $pipeline->send($context)->through($pipes)->thenReturn();

        $reporter->finish();

        if ($context->options->follow && $combined->hasProcesses()) {
            return $this->streamCombinedOutput($combined, $multiplexer, $shutdown);
        }

        return $this->done('Application ready.');
    }

    /**
     * The composer-run-dev experience: after the boot, stay in the
     * foreground and interleave every combined worker's output here. The
     * loop ends on Ctrl+C (the trap stops it before tearing down) or when
     * every worker has exited — either way one shared shutdown path runs,
     * a friendly no-op when app:down from another terminal already did.
     */
    private function streamCombinedOutput(
        CombinedRunPlan $combined,
        OutputMultiplexer $multiplexer,
        ShutdownRunner $shutdown,
    ): int {
        terminal()->outro('Application ready.');
        terminal()->info('Streaming service output below — press Ctrl+C to stop everything.');

        $this->streaming = $multiplexer;
        $multiplexer->stream($combined);
        $this->streaming = null;

        $shutdown->run();

        return self::SUCCESS;
    }

    /**
     * Ctrl-C / SIGTERM tears everything down through the shared shutdown
     * path. Must be registered AFTER StageReporter::begin():
     * Progress::start() installs its own SIGINT handler, which would
     * otherwise replace this one and skip the shutdown entirely.
     */
    private function registerShutdownTrap(ShutdownRunner $shutdown, StageReporter $reporter): void
    {
        $this->trap([SIGINT, SIGTERM], function () use ($shutdown, $reporter): void {
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
            $reporter->interrupt();
            $shutdown->run();
            exit(self::SUCCESS);
        });
    }

    protected function onFailure(): void
    {
        $this->reporter?->fail();
    }

    protected function failureHint(): void
    {
        terminal()->note('Background processes may still be running — clean up with: php artisan app:down');
    }

    private function serveOptions(): ServeOptions
    {
        return new ServeOptions(
            seed: (bool) $this->option('seed'),
            migrate: ! $this->option('no-migrate'),
            update: (bool) $this->option('update'),
            withQueue: ! $this->option('without-queue'),
            withAssets: ! $this->option('without-assets'),
            fresh: (bool) $this->option('fresh'),
            follow: ! $this->option('detach') && $this->stdoutIsInteractive(),
        );
    }

    /**
     * Piped or redirected stdout (CI, scripts) cannot host the combined
     * stream — workers silently fall back to background mode there.
     */
    private function stdoutIsInteractive(): bool
    {
        return \defined('STDOUT') && \function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }

    private function anotherServeIsRunning(ActiveServerStore $store, ServeProcessProbe $probe): bool
    {
        $active = $store->current();

        if ($active === null || $active->servePid === getmypid()) {
            return false;
        }

        return $probe->isServing($active->servePid);
    }
}
