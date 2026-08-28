<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Herd;

use Igne\LaravelBootUp\Config\HerdConfig;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Owns Herd's runtime health. Two distinct signals:
 *
 *  - isRunning(): at least one of Herd's OWN core processes is alive. The
 *    patterns require the Herd installation path in the command line; a bare
 *    service name would match any nginx/php-fpm on the host and corrupt the
 *    started-by-us bookkeeping in both directions.
 *  - isReachable(): Nginx actually answers an HTTPS request for the served
 *    site. A live process is not proof the site works, so this is the signal
 *    the boot waits on before reporting the server ready.
 */
final class HerdServices
{
    private const array SERVICE_PATTERNS = [
        'Herd[^ ]*nginx',
        'Herd[^ ]*php-fpm',
    ];

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly HerdConfig $config,
    ) {}

    public function isRunning(): bool
    {
        // contains() short-circuits, so the php-fpm pgrep is skipped once
        // nginx already matched — each check spawns a process.
        return collect(self::SERVICE_PATTERNS)->contains(
            fn (string $pattern): bool => $this->runner->runSilently(CommandLine::make(['pgrep', '-f', $pattern]))->successful(),
        );
    }

    /**
     * Nginx is reachable when curl gets any HTTP response back (even a 5xx
     * proves nginx + php-fpm answered). A refused/timed-out connection exits
     * non-zero and reports "000", which is not reachable. --retry-connrefused
     * rides out a momentary Nginx reload (e.g. right after `herd secure`)
     * instead of counting it as a hard failure.
     */
    public function isReachable(string $url): bool
    {
        $result = $this->runner->runSilently(CommandLine::make([
            'curl', '--silent', '--insecure', '--output', '/dev/null',
            '--write-out', '%{http_code}',
            '--retry', '3', '--retry-connrefused', '--retry-delay', '1',
            '--max-time', (string) $this->config->healthTimeoutSeconds,
            $url,
        ]));

        return $result->successful() && preg_match('/^[1-5]\d\d$/', trim($result->output())) === 1;
    }

    public function boot(): void
    {
        $this->runner->runSilently(CommandLine::make('herd start'));
    }

    public function restart(): void
    {
        $this->runner->runSilently(CommandLine::make('herd restart'));
    }

    /**
     * Block until the served site answers, or fail with actionable guidance.
     * A healthy server returns on the first check without ever restarting.
     *
     * A running Herd is NEVER restarted here: if its own daemons are up, a
     * failing probe means a cold PHP-FPM, a TLS handshake or a momentary reload
     * that the remaining attempts (and the probe's own connect-refused retries)
     * ride out — knocking it over would only disrupt every other Herd site on
     * the machine. Only a Herd whose services are genuinely down gets a single
     * midpoint restart to try to recover it.
     */
    public function ensureReachable(string $url): void
    {
        $restartAt = intdiv($this->config->healthAttempts, 2);

        for ($attempt = 1; $attempt <= $this->config->healthAttempts; $attempt++) {
            if ($this->isReachable($url)) {
                return;
            }

            if ($attempt === $restartAt && ! $this->isRunning()) {
                terminal()->info('Laravel Herd is not answering and its services are down — restarting all Herd sites on this machine...');
                $this->restart();
            }

            usleep($this->config->healthDelayMs * 1000);
        }

        throw ServerException::unreachable($url, $this->config->healthAttempts);
    }
}
