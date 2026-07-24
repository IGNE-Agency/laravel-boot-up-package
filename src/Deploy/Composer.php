<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Exceptions\DeployException;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\LockfileConflictDetector;
use Illuminate\Process\Exceptions\ProcessFailedException;

/**
 * Installs the project's composer dependencies. Always runs host-side —
 * under Sail, vendor/bin/sail cannot exist before composer install has run,
 * so these commands are deliberately never rewritten.
 */
final class Composer
{
    /**
     * A dependency install can legitimately take many minutes on a slow
     * network or a large project; the default per-command timeout is meant for
     * quick commands and would abort a real install mid-way, so it is lifted
     * well clear here while still bounding a genuinely hung process.
     */
    private const INSTALL_TIMEOUT_SECONDS = 1800;

    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly LockfileConflictDetector $conflicts,
        private readonly string $basePath,
    ) {}

    public function install(bool $update = false): void
    {
        if (! $update && ! $this->installNeeded()) {
            terminal()->note('Composer dependencies already up to date — skipping (use --update to force).');

            return;
        }

        terminal()->info($update ? 'Updating composer dependencies...' : 'Installing composer dependencies...');

        try {
            $this->run($update ? 'composer update' : 'composer install');
        } catch (ProcessFailedException $exception) {
            if ($update || ! $this->conflicts->isLockfileConflict($this->outputOf($exception))) {
                throw DeployException::composerFailed($exception->getMessage());
            }

            $this->regenerateLockfileAndRetry();
        }
    }

    /**
     * Whether vendor/ is out of sync with the lockfile and an install is worth
     * running. installed.json is the file Composer rewrites after every
     * successful install/update, so its mtime is the canonical "last install"
     * time; a newer composer.lock (a git pull/merge/branch switch) or a
     * hand-edited composer.json means vendor is stale. Missing vendor or lock
     * always installs (and lets the stale-lock retry handle a bad lock).
     */
    private function installNeeded(): bool
    {
        $installed = $this->basePath.'/vendor/composer/installed.json';

        if (! is_file($this->basePath.'/vendor/autoload.php') || ! is_file($installed)) {
            return true;
        }

        $lock = $this->basePath.'/composer.lock';

        if (! is_file($lock)) {
            return true;
        }

        $installedAt = (int) filemtime($installed);

        if ((int) filemtime($lock) > $installedAt) {
            return true;
        }

        $manifest = $this->basePath.'/composer.json';

        return is_file($manifest) && (int) filemtime($manifest) > $installedAt;
    }

    private function regenerateLockfileAndRetry(): void
    {
        terminal()->warning('composer.lock is out of sync with composer.json; regenerating it without changing versions...');

        try {
            $this->run('composer update --lock');
            $this->run('composer install');
        } catch (ProcessFailedException $exception) {
            throw DeployException::composerFailed($exception->getMessage());
        }
    }

    private function run(string $command): void
    {
        $this->processes->run(CommandLine::make($command)->withTimeout(self::INSTALL_TIMEOUT_SECONDS));
    }

    private function outputOf(ProcessFailedException $exception): string
    {
        return $exception->result->output()."\n".$exception->result->errorOutput();
    }
}
