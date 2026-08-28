<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Console\OpenBrowserCommand;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Enums\BuiltInProcess;
use Igne\LaravelBootUp\Frontend\ViteHotFile;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Throwable;

/**
 * Opens the served URL at the moment it is worth looking at.
 *
 * The boot cannot do this itself. `php artisan dev` is what starts the Vite
 * watcher and, under the artisan driver, `php artisan serve` — so a browser
 * opened when the pipeline ends lands on a refused connection or a Vite
 * manifest exception. And app:up cannot wait for them either: handing the
 * terminal to `dev` blocks until the user quits it.
 *
 * So the wait runs beside the dev terminal, in a tracked background process.
 * Tracked matters: a user who quits the dev terminal after ten seconds has
 * their pending browser cancelled by the teardown, instead of getting a tab
 * onto a dead application fifty seconds later.
 *
 * When nothing the page needs starts after the boot — Herd serving prebuilt
 * assets, say — there is nothing to wait for and the browser opens inline,
 * exactly as it always did.
 */
final class DeferredBrowser
{
    /**
     * The ledger label and log file name of the waiting process. Not a
     * BuiltInProcess: those name the tabs `php artisan dev` runs, and this
     * never appears in the dev terminal.
     */
    private const string LABEL = 'browser';

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Browser $browser,
        private readonly ViteHotFile $hotFile,
        private readonly SetupConfig $config,
        private readonly string $artisanPath,
    ) {}

    /**
     * @param  list<string>  $devProcesses  the names `php artisan dev` will run
     */
    public function open(BootContext $context, array $devProcesses): void
    {
        if (! $this->config->openBrowser || $context->server === null) {
            return;
        }

        $url = $context->server->url();

        $awaitsAssets = \in_array(BuiltInProcess::Vite->value, $devProcesses, true);
        $awaitsServer = \in_array(BuiltInProcess::Server->value, $devProcesses, true);

        if (! $awaitsAssets && ! $awaitsServer) {
            $this->browser->open($url);

            return;
        }

        // The watcher about to start writes public/hot itself; one left behind
        // by a run that was killed would read as "Vite is serving" and point
        // the page at a dev server that is gone. Only when this run brings its
        // own watcher, so a Vite someone is running by hand is left alone.
        if ($awaitsAssets) {
            $this->hotFile->remove();
        }

        $this->waitFor($url, $awaitsAssets);
    }

    /**
     * PHP_BINARY and an absolute artisan path rather than `php artisan`, and
     * deliberately no CommandRewriter: unlike a dev process, this one belongs
     * to the host. Under Sail a rewrite would run it inside the container,
     * where there is no browser to open and localhost is a different machine.
     * PHP_BINARY is also, by definition, an interpreter that can boot this
     * project — under Herd the site's PHP rather than whatever PATH offers.
     */
    private function waitFor(string $url, bool $awaitsAssets): void
    {
        $tokens = [PHP_BINARY, $this->artisanPath, OpenBrowserCommand::NAME, $url];

        if ($awaitsAssets) {
            $tokens[] = '--vite';
        }

        try {
            $this->runner->start(CommandLine::make($tokens), self::LABEL);
        } catch (Throwable $exception) {
            // A browser is a convenience, and this runs one line before the
            // dev handover: never fail a whole boot over it.
            terminal()->warning("Could not schedule the browser: {$exception->getMessage()} — open {$url} yourself once the dev processes are up.");

            return;
        }

        // Without this, a twenty-second wait for a cold Vite reads as broken.
        terminal()->note("Your browser opens at {$url} as soon as the application can render it.");
    }
}
