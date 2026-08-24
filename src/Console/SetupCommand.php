<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Closure;
use Igne\LaravelBootUp\Boot\BootRunner;
use Igne\LaravelBootUp\Boot\DevProcessRegistrar;
use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Data\BootOptions;
use Illuminate\Contracts\Console\Isolatable;

/**
 * Gets the project to a state where `php artisan dev` can run: a server
 * serving it, dependencies installed, .env written, the database migrated.
 *
 * Everything that takes real work lives here rather than in `dev`, which
 * exists to hand a ready project to Laravel's terminal UI in milliseconds.
 * Run this once after a clone, and again whenever the project's shape
 * changes; `dev` is what gets run every day.
 */
final class SetupCommand extends BootUpCommand implements Isolatable
{
    protected $signature = 'app:setup
        {server? : The development server to use (herd, sail, artisan, or any driver registered in boot-up.server.drivers)}
        {--s|seed : Seed the database after migrating}
        {--no-migrate : Skip running pending migrations}
        {--fresh : Drop all tables and re-run every migration (asks first)}
        {--u|update : Update dependencies instead of installing}
        {--without-assets : Skip frontend dependencies and assets}
        {--y|yes : Run without the confirmation prompt}';

    protected $description = 'Set up the application and start its development server';

    private ?BootRunner $runner = null;

    /**
     * The boot signals processes, reads the process table and links sites, so
     * it needs a Unix-like environment.
     */
    protected function requiresUnix(): bool
    {
        return true;
    }

    public function handle(BootRunner $runner, DevProcessRegistrar $registrar, SetupConfig $config): int
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

        if (! $this->confirmPlan($plan, 'app:setup', $config->autoAccept)) {
            return $this->skip('Aborted — nothing was changed.');
        }

        $context = $runner->run(fn (array $signals, Closure $handler) => $this->trap($signals, $handler));

        // Only now are .env, composer.json and package.json final, so only
        // now can the gates say what dev will actually run.
        $processes = $registrar->preview($context);

        if ($processes === []) {
            terminal()->note('This project has no dev processes to run, so there is nothing for php artisan dev to stream.');
        } else {
            terminal()->summary('Next: php artisan dev', $processes, 'Stop the server with: php artisan app:down');
        }

        return $this->done('The application is set up.');
    }

    protected function onFailure(): void
    {
        $this->runner?->fail();
    }

    protected function failureHint(): void
    {
        terminal()->note('Background processes may still be running — clean up with: php artisan app:down');
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
