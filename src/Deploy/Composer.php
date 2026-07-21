<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy;

use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Support\LockfileConflictDetector;
use Illuminate\Process\Exceptions\ProcessFailedException;

/**
 * Installs the project's composer dependencies. Always runs host-side —
 * under Sail, vendor/bin/sail cannot exist before composer install has run,
 * so these commands are deliberately never rewritten.
 */
final class Composer
{
    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly LockfileConflictDetector $conflicts,
    ) {}

    public function install(bool $update = false): void
    {
        terminal()->info($update ? 'Updating composer dependencies...' : 'Installing composer dependencies...');

        try {
            $this->processes->run(ShellCommand::make($update ? 'composer update' : 'composer install'));
        } catch (ProcessFailedException $exception) {
            if ($update || ! $this->conflicts->isLockfileConflict($this->outputOf($exception))) {
                throw DeployException::composerFailed($exception->getMessage());
            }

            $this->regenerateLockfileAndRetry();
        }
    }

    private function regenerateLockfileAndRetry(): void
    {
        terminal()->warning('composer.lock is out of sync with composer.json; regenerating it without changing versions...');

        try {
            $this->processes->run(ShellCommand::make('composer update --lock'));
            $this->processes->run(ShellCommand::make('composer install'));
        } catch (ProcessFailedException $exception) {
            throw DeployException::composerFailed($exception->getMessage());
        }
    }

    private function outputOf(ProcessFailedException $exception): string
    {
        return $exception->result->output()."\n".$exception->result->errorOutput();
    }
}
