<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Asks whether a host port can still be bound.
 *
 * The probe binds 0.0.0.0 because that is what Docker binds: a listener on
 * a single interface (Herd's nginx on 127.0.0.1:80) or on IPv6 alone
 * (Homebrew's mysqld on *:3306) blocks a wildcard bind just the same, and
 * only attempting the same bind reproduces that faithfully. A connect probe
 * would miss both.
 */
final class PortProbe
{
    /**
     * How far past a taken port nextAvailable() will look before giving up.
     */
    private const int SEARCH_ATTEMPTS = 50;

    public function __construct(private readonly ProcessRunner $runner) {}

    /**
     * Only an in-use error counts as unavailable. Every other failure --
     * a privileged port on Linux, a sandboxed socket layer -- is
     * inconclusive, and an inconclusive probe must never block a boot that
     * would have worked.
     */
    public function isAvailable(int $port): bool
    {
        $socket = @stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $error);

        if ($socket !== false) {
            fclose($socket);

            return true;
        }

        return stripos($error, 'address already in use') === false;
    }

    /**
     * The first free port at or after $from, or null when the search runs
     * out — there is no sensible fallback to invent for the caller.
     */
    public function nextAvailable(int $from, int $attempts = self::SEARCH_ATTEMPTS): ?int
    {
        for ($port = $from; $port < min($from + $attempts, 65536); $port++) {
            if ($this->isAvailable($port)) {
                return $port;
            }
        }

        return null;
    }

    /**
     * What is holding the port, as "mysqld (PID 7026)" -- or null when lsof
     * is absent or finds nothing. Purely to make the message actionable, so
     * every failure here is silent.
     */
    public function holderOf(int $port): ?string
    {
        if (! $this->runner->isCommandAvailable('lsof')) {
            return null;
        }

        // -F is lsof's machine-readable mode (one field per line, prefixed
        // by its letter) and +c 0 stops it truncating command names to nine
        // characters; parsing the human table would be guesswork.
        $result = $this->runner->runSilently(CommandLine::make([
            'lsof', '-nP', "-iTCP:{$port}", '-sTCP:LISTEN', '+c', '0', '-Fpc',
        ]));

        if (! $result->successful()) {
            return null;
        }

        return $this->describe($result->output());
    }

    private function describe(string $output): ?string
    {
        $pid = null;
        $command = null;

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $value = substr($line, 1);

            match ($line[0] ?? '') {
                'p' => $pid ??= $value,
                'c' => $command ??= $value,
                default => null,
            };

            if ($pid !== null && $command !== null) {
                break;
            }
        }

        if ($command === null) {
            return null;
        }

        return $pid === null ? $command : "{$command} (PID {$pid})";
    }
}
