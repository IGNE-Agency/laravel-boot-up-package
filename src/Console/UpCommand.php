<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Closure;
use Igne\LaravelBootUp\Boot\BootRunner;
use Igne\LaravelBootUp\Boot\DeferredBrowser;
use Igne\LaravelBootUp\Boot\DevProcessRegistrar;
use Igne\LaravelBootUp\Boot\DevSession;
use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Illuminate\Contracts\Console\Isolatable;

/**
 * Gets the project to a state where `php artisan dev` can run: a server
 * serving it, dependencies installed, .env written, the database migrated —
 * and then runs it, because being set up and not running is not a state
 * anyone wants to be left in.
 *
 * Everything that takes real work lives here rather than in `dev`, which
 * exists to hand a ready project to Laravel's terminal UI in milliseconds.
 * Run this once after a clone, and again whenever the project's shape
 * changes; `dev` is what gets run every day.
 *
 * The whole session belongs to this command: the boot, the dev terminal it
 * hands over to, and the teardown when that terminal quits. What this run
 * started, this run stops.
 */
final class UpCommand extends BootUpCommand implements Isolatable
{
    protected $signature = 'app:up
        {server? : The development server to use (herd, sail, artisan, or any driver registered in boot-up.server.drivers)}
        {--s|seed : Seed the database after migrating}
        {--no-migrate : Skip running pending migrations}
        {--fresh : Drop all tables and re-run every migration (asks first)}
        {--u|update : Update dependencies instead of installing}
        {--without-assets : Skip frontend dependencies and assets}
        {--y|yes : Run without the confirmation prompt}';

    protected $description = 'Set up the application, run it, and stop it again when you quit';

    private ?BootRunner $runner = null;

    /**
     * The boot signals processes, reads the process table and links sites, so
     * it needs a Unix-like environment.
     */
    protected function requiresUnix(): bool
    {
        return true;
    }

    public function handle(BootRunner $runner, DevProcessRegistrar $registrar, SetupConfig $config, DevSession $session, DeferredBrowser $browser): int
    {
        // Stored before anything can fail: onFailure() fires from
        // GuardsAgainstFailures OUTSIDE handle(), where re-resolving would
        // produce a fresh runner with a fresh, unbound reporter.
        $this->runner = $runner;

        $server = $this->argument('server');
        $plan = $runner->prepare($this->bootOptions(), \is_string($server) ? $server : null);

        if ($plan === null) {
            return self::FAILURE;
        }

        if (! $this->confirmPlan($plan, 'app:up', $config->autoAccept, 'Then hand over to php artisan dev, and stop it all again when you quit.')) {
            return $this->skip('Aborted — nothing was changed.');
        }

        $context = $runner->run(fn (array $signals, Closure $handler) => $this->trap($signals, $handler));

        // Only now are .env, composer.json and package.json final, so only
        // now can the gates say what dev will actually run.
        $processes = $registrar->preview($context);

        // These names are a prediction: `dev` re-derives the same set from the
        // same gates over the same project a moment from now. That is what the
        // browser waits on, so the wait stays out of the dev command entirely
        // — and were the two ever to disagree, the wait times out and the
        // browser opens anyway.
        $browser->open($context, $processes);

        if ($processes === []) {
            terminal()->note('This project has no dev processes to run, so there is nothing for php artisan dev to stream.');

            return $this->done('The application is set up.');
        }

        terminal()->summary('Starting php artisan dev', $processes, 'Quit the dev terminal and everything this setup started is stopped for you.');

        return $this->runDevSession($context, $session);
    }

    protected function onFailure(): void
    {
        $this->runner?->fail();
    }

    protected function failureHint(): void
    {
        terminal()->note('Background processes may still be running — clean up with: php artisan app:down');
    }

    /**
     * Hand the rest of the run to `dev`, and take it back when the terminal
     * quits.
     *
     * Claiming the session is what keeps this process alive behind the
     * terminal UI; without it `dev` execs itself away and nobody is left to
     * stop the server this boot started. app:down does that stopping — the
     * same command with the same prompts, run for the user instead of by
     * them — and it speaks for the ending, so there is no outro of our own
     * on this path.
     */
    private function runDevSession(BootContext $context, DevSession $session): int
    {
        $session->claim();

        $exitCode = $this->call('dev', $this->devArguments($context));

        $this->call('app:down');

        // dev's own code, not a fresh one: a session that ended badly should
        // say so through the command that started it.
        return $exitCode;
    }

    /**
     * What the boot already settled, handed over rather than rediscovered:
     * the server this run picked, and the assets it was told to leave alone.
     *
     * @return array<string, mixed>
     */
    private function devArguments(BootContext $context): array
    {
        $arguments = [];

        if ($context->server !== null) {
            $arguments['server'] = $context->server->key();
        }

        if ($this->option('without-assets')) {
            $arguments['--without-assets'] = true;
        }

        return $arguments;
    }

    private function bootOptions(): BootOptions
    {
        return new BootOptions(
            seed: (bool) $this->option('seed'),
            migrate: ! $this->option('no-migrate'),
            update: (bool) $this->option('update'),
            withAssets: ! $this->option('without-assets'),
            fresh: (bool) $this->option('fresh'),
        );
    }
}
