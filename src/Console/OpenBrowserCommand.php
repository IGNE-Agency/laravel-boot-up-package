<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Boot\Browser;
use Igne\LaravelBootUp\Boot\UrlProbe;
use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Frontend\ViteHotFile;
use Igne\LaravelBootUp\Services\Poller;

/**
 * Waits until the application can render, then opens it in the browser.
 *
 * app:up runs this detached, because the wait has to happen alongside the
 * dev terminal rather than before it: `php artisan dev` is what starts the
 * Vite watcher and, under the artisan driver, `php artisan serve` — so at the
 * moment the boot ends, the URL it just announced either refuses the
 * connection or answers with a Vite manifest exception. Waiting here means
 * the first page the user sees is the application.
 *
 * Hidden because nobody needs to type it: it is app:up's own machinery, and
 * a URL is only worth waiting for when something is on its way up. It stays
 * a real command so the wait is testable, reuses Browser's platform matrix
 * rather than re-deriving it in shell, and reports itself through the ledger
 * and app:status like every other tracked process.
 */
final class OpenBrowserCommand extends BootUpCommand
{
    /**
     * Named once: DeferredBrowser builds the command line that invokes this.
     */
    public const string NAME = 'app:open-browser';

    protected $signature = self::NAME.'
        {url : The URL to open}
        {--vite : Wait for the Vite dev server to write its hot file first}';

    protected $description = 'Open the application in the browser once it can render (used by app:up)';

    protected $hidden = true;

    public function handle(UrlProbe $probe, ViteHotFile $hotFile, Browser $browser, Poller $poller, SetupConfig $config): int
    {
        $url = (string) $this->argument('url');
        $timeout = $config->browserWaitTimeoutSeconds;
        $deadline = microtime(true) + $timeout;

        $this->announce("Waiting for {$url} to be ready...");

        if (! $this->answers($probe, $poller, $url, $timeout, $config->browserPollIntervalMs)) {
            terminal()->warning("Nothing answered at {$url} within {$timeout}s — opening it anyway.");
        } elseif (! $this->assetsAreServed($hotFile, $poller, $deadline, $config->browserPollIntervalMs)) {
            terminal()->warning("The Vite dev server did not come up within {$timeout}s — opening {$url} anyway; reload it once Vite is serving.");
        } else {
            terminal()->success("The application answered — opening {$url}");
        }

        $browser->open($url);

        // Whatever it took, a browser was opened at the URL it was given —
        // the warnings above are the record of how ready it was.
        return $this->done("Opened {$url}.");
    }

    /**
     * The reachability wait, on its own and first.
     *
     * Every probe is an HTTP request that boots the framework at the other
     * end, so this is the expensive half; once the server answers it keeps
     * answering, and the hot-file wait that follows is a bare stat() loop.
     *
     * A machine without curl skips the gate rather than stalling until the
     * timeout on a question it cannot ask.
     */
    private function answers(UrlProbe $probe, Poller $poller, string $url, int $timeout, int $intervalMs): bool
    {
        if (! $probe->isAvailable()) {
            return true;
        }

        return $poller->until(fn (): bool => $probe->isAnswering($url), $timeout, $intervalMs);
    }

    /**
     * Vite writes public/hot when its dev server starts listening, and that
     * marker is exactly what `@vite` reads to decide between dev-server tags
     * and the build manifest — so it answers the question the page asks.
     *
     * Both waits share one deadline: the point is to bound how long the user
     * waits for a browser, not how long each check may take.
     */
    private function assetsAreServed(ViteHotFile $hotFile, Poller $poller, float $deadline, int $intervalMs): bool
    {
        if (! $this->option('vite')) {
            return true;
        }

        return $poller->until(fn (): bool => $hotFile->exists(), $this->secondsLeft($deadline), $intervalMs);
    }

    private function secondsLeft(float $deadline): int
    {
        return max(0, (int) ceil($deadline - microtime(true)));
    }
}
