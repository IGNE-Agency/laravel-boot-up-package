<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Asks one question: is anything answering HTTP at this URL?
 *
 * Any status counts, 5xx included — a Vite manifest exception proves the
 * server answered, and separating "is it listening" from "can it render" is
 * the whole reason this exists. A refused or timed-out connection exits
 * non-zero and reports "000", which is not answering.
 *
 * Deliberately not folded into HerdServices::isReachable(), which asks the
 * same question with different flags on purpose: it rides out a momentary
 * Nginx reload with curl's own --retry-connrefused, while here the poll loop
 * is the retry and per-probe retries would stretch one check past the
 * interval it was given.
 */
final class UrlProbe
{
    /** Long enough for a TLS handshake, short enough not to swallow a poll. */
    private const int CONNECT_TIMEOUT_SECONDS = 2;

    private const int REQUEST_TIMEOUT_SECONDS = 5;

    private ?bool $available = null;

    public function __construct(private readonly ProcessRunner $runner) {}

    public function isAnswering(string $url): bool
    {
        $result = $this->runner->runSilently(CommandLine::make([
            'curl', '--silent', '--insecure',
            '--output', '/dev/null',
            '--write-out', '%{http_code}',
            '--connect-timeout', (string) self::CONNECT_TIMEOUT_SECONDS,
            '--max-time', (string) self::REQUEST_TIMEOUT_SECONDS,
            $url,
        ]));

        return $result->successful() && preg_match('/^[1-5]\d\d$/', trim($result->output())) === 1;
    }

    /**
     * Whether the probe can be asked at all. Memoised: callers poll, and the
     * answer cannot change inside one run.
     */
    public function isAvailable(): bool
    {
        return $this->available ??= $this->runner->isCommandAvailable('curl');
    }
}
