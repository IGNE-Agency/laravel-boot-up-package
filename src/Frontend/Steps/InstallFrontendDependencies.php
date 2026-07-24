<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend\Steps;

use Closure;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Exceptions\FrontendException;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\LockfileConflictDetector;
use Illuminate\Process\Exceptions\ProcessFailedException;

final class InstallFrontendDependencies implements Step
{
    /**
     * Installing node modules can take minutes on a slow network or a large
     * project; the default per-command timeout is meant for quick commands and
     * would abort a real install mid-way, so it is lifted well clear here.
     */
    private const INSTALL_TIMEOUT_SECONDS = 1800;

    public function __construct(
        private readonly PackageManagerSelector $selector,
        private readonly PackageJson $packageJson,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly LockfileConflictDetector $conflicts,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $context->options->withAssets) {
            terminal()->note('Frontend dependencies skipped (--without-assets).');

            return $next($context);
        }

        if (! $this->packageJson->exists()) {
            terminal()->note('No package.json found — skipping frontend dependencies.');

            return $next($context);
        }

        $manager = $this->selector->selected();

        $command = $this->rewriter->rewrite(
            CommandLine::make($context->options->update ? $manager->updateCommand() : $manager->installCommand())
                ->withTimeout(self::INSTALL_TIMEOUT_SECONDS),
            $context->commandRewrites(),
        );

        terminal()->info(($context->options->update ? 'Updating' : 'Installing')." frontend dependencies with {$manager->value}...");

        try {
            $this->runner->run($command);
        } catch (ProcessFailedException $exception) {
            $this->recoverFromLockfileConflict($manager->value, $command, $exception);
        }

        return $next($context);
    }

    /**
     * A stale lockfile is the one failure worth retrying: the package manager
     * refreshes the lockfile on the next run. Anything else aborts the boot.
     */
    private function recoverFromLockfileConflict(
        string $manager,
        CommandLine $command,
        ProcessFailedException $exception,
    ): void {
        if (! $this->conflicts->isLockfileConflict($this->failureReason($exception))) {
            throw FrontendException::installFailed($manager, $this->failureReason($exception));
        }

        terminal()->warning('Lockfile is out of sync with package.json — retrying once.');

        try {
            $this->runner->run($command);
        } catch (ProcessFailedException $retry) {
            throw FrontendException::installFailed($manager, $this->failureReason($retry));
        }
    }

    private function failureReason(ProcessFailedException $exception): string
    {
        $output = trim($exception->result->output()."\n".$exception->result->errorOutput());

        return $output !== '' ? $output : $exception->getMessage();
    }
}
