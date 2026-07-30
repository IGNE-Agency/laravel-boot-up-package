<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Closure;
use Igne\LaravelBootUp\Config\ServeConfig;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Serve\ServeRunner;
use Illuminate\Contracts\Console\Isolatable;

final class ServeCommand extends BootUpCommand implements Isolatable
{
    private ?ServeRunner $runner = null;

    protected $signature = 'app:serve {server? : The development server to use (herd, sail, artisan, or any driver registered in boot-up.server.drivers)}
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

    public function handle(ServeRunner $runner, ServeConfig $config): int
    {
        // Stored before anything can fail: onFailure() fires from
        // GuardsAgainstFailures OUTSIDE handle(), where re-resolving
        // would produce a fresh runner with a fresh, unbound reporter.
        $this->runner = $runner;

        $plan = $runner->prepare($this->serveOptions(), $this->argument('server'));

        if ($plan === null) {
            return self::FAILURE;
        }

        if (! $this->confirmPlan($plan, 'app:serve', $config->autoAccept)) {
            return $this->skip('Aborted — nothing was changed.');
        }

        // The runner owns its endings ("Application ready." on both paths);
        // the trap is handed over as a REGISTRAR because the handler must
        // close over runner-owned state.
        return $runner->run(fn (array $signals, Closure $handler) => $this->trap($signals, $handler));
    }

    protected function onFailure(): void
    {
        $this->runner?->fail();
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
}
