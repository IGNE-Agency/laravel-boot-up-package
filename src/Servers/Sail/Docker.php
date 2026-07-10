<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Servers\Sail;

use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Servers\ServerException;
use Igne\LaravelBootstrap\Support\Poller;

use function Laravel\Prompts\info;

final class Docker
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Poller $poller,
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

        info('Starting Docker...');

        PHP_OS_FAMILY === 'Darwin'
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
