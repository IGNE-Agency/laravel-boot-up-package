<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Herd;

use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Servers\ServerException;

/**
 * Owns Herd's runtime health. Two distinct signals:
 *
 *  - isRunning(): at least one of Herd's OWN core processes is alive. The
 *    patterns require the Herd installation path in the command line; a bare
 *    service name would match any nginx/php-fpm on the host and corrupt the
 *    started-by-us bookkeeping in both directions.
 *  - isReachable(): Nginx actually answers an HTTPS request for the served
 *    site. A live process is not proof the site works, so this is the signal
 *    app:serve waits on before reporting the server ready.
 */
final class HerdServices
{
    private const array SERVICE_PATTERNS = [
        'Herd[^ ]*nginx',
        'Herd[^ ]*php-fpm',
    ];

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly int $healthAttempts = 10,
        private readonly int $healthDelayMs = 500,
        private readonly int $healthTimeoutSeconds = 5,
    ) {}

    public function isRunning(): bool
    {
        foreach (self::SERVICE_PATTERNS as $pattern) {
            if ($this->runner->runSilently(ShellCommand::make(['pgrep', '-f', $pattern]))->successful()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nginx is reachable when curl gets any HTTP response back (even a 5xx
     * proves nginx + php-fpm answered). A refused/timed-out connection exits
     * non-zero and reports "000", which is not reachable.
     */
    public function isReachable(string $url): bool
    {
        $result = $this->runner->runSilently(ShellCommand::make([
            'curl', '--silent', '--insecure', '--output', '/dev/null',
            '--write-out', '%{http_code}',
            '--max-time', (string) $this->healthTimeoutSeconds,
            $url,
        ]));

        return $result->successful() && preg_match('/^[1-5]\d\d$/', trim($result->output())) === 1;
    }

    public function boot(): void
    {
        $this->runner->runSilently(ShellCommand::make('herd start'));
    }

    public function restart(): void
    {
        $this->runner->runSilently(ShellCommand::make('herd restart'));
    }

    /**
     * Block until the served site answers, or fail with actionable guidance.
     * A healthy server returns on the first check without ever restarting.
     *
     * Herd is restarted at most ONCE, halfway through the attempts: the first
     * half give a running-but-slow Herd time to answer its first request
     * (a cold PHP-FPM or TLS handshake), and only if it still will not answer
     * do we restart to recover a genuinely stuck Nginx, leaving the remaining
     * attempts for it to come back. Restarting on every failed check would
     * knock over a Herd that was merely slow and never let it settle.
     */
    public function ensureReachable(string $url): void
    {
        $restartAt = intdiv($this->healthAttempts, 2);

        for ($attempt = 1; $attempt <= $this->healthAttempts; $attempt++) {
            if ($this->isReachable($url)) {
                return;
            }

            if ($attempt === $restartAt) {
                terminal()->info('Herd is not answering yet — restarting it...');
                $this->restart();
            }

            usleep($this->healthDelayMs * 1000);
        }

        throw ServerException::unreachable($url, $this->healthAttempts);
    }
}
