<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Data\ShellCommand;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\Platform;
use Igne\LaravelBootUp\Services\Poller;

final class Docker
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Poller $poller,
        private readonly Platform $platform,
        private readonly int $startTimeoutSeconds = 60,
    ) {}

    public function isRunning(): bool
    {
        return $this->runner->runSilently(ShellCommand::make(['docker', 'info']))->successful();
    }

    public function ensureRunning(): void
    {
        if ($this->isRunning()) {
            return;
        }

        terminal()->info('Starting Docker...');

        $this->platform->isMacos()
            ? $this->runner->runSilently(ShellCommand::make(['open', '-a', 'Docker']))
            : $this->runner->runSilently(ShellCommand::make(['systemctl', 'start', 'docker']));

        $started = $this->poller->until(
            fn (): bool => $this->isRunning(),
            timeoutSeconds: $this->startTimeoutSeconds,
        );

        if (! $started) {
            throw ServerException::dockerUnavailable();
        }
    }
}
