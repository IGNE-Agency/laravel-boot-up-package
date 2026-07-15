<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Frontend\Steps;

use Closure;
use Igne\LaravelBootstrap\Frontend\FrontendException;
use Igne\LaravelBootstrap\Frontend\PackageJson;
use Igne\LaravelBootstrap\Frontend\PackageManagerSelector;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;
use Igne\LaravelBootstrap\Servers\CommandRewriter;
use Igne\LaravelBootstrap\Support\LockfileConflictDetector;
use Illuminate\Process\Exceptions\ProcessFailedException;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

final class InstallFrontendDependencies implements Step
{
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
            note('Frontend dependencies skipped (--without-assets).');

            return $next($context);
        }

        if (! $this->packageJson->exists()) {
            note('No package.json found — skipping frontend dependencies.');

            return $next($context);
        }

        $manager = $this->selector->selected();

        $command = $this->rewriter->rewrite(
            ShellCommand::make($context->options->update ? $manager->updateCommand() : $manager->installCommand()),
            $context->server?->commandRewrites(),
        );

        info(($context->options->update ? 'Updating' : 'Installing')." frontend dependencies with {$manager->value}...");

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
        ShellCommand $command,
        ProcessFailedException $exception,
    ): void {
        if (! $this->conflicts->isLockfileConflict($this->failureReason($exception))) {
            throw FrontendException::installFailed($manager, $this->failureReason($exception));
        }

        warning('Lockfile is out of sync with package.json — retrying once.');

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
