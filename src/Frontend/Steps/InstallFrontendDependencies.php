<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\ReadsProcessFailureOutput;
use Igne\LaravelBootUp\Concerns\SkipsWithNote;
use Igne\LaravelBootUp\Config\ProcessConfig;
use Igne\LaravelBootUp\Contracts\DescribesProgress;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Exceptions\FrontendException;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\LockfileConflictDetector;
use Illuminate\Process\Exceptions\ProcessFailedException;

#[Stage(BootStage::Install)]
#[Group('dependencies')]
final class InstallFrontendDependencies implements DescribesProgress, Step
{
    use ReadsProcessFailureOutput;

    use SkipsWithNote;

    public function __construct(
        private readonly PackageManagerSelector $selector,
        private readonly PackageJson $packageJson,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly LockfileConflictDetector $conflicts,
        private readonly ProcessConfig $processConfig,
    ) {}

    public function handle(BootContext $context, Closure $next): mixed
    {
        if (! $context->options->withAssets) {
            return $this->skipStep('Frontend dependencies skipped (--without-assets).', $context, $next);
        }

        if (! $this->packageJson->exists()) {
            return $this->skipStep('No package.json found — skipping frontend dependencies.', $context, $next);
        }

        $manager = $this->selector->selected();

        $command = $this->rewriter->rewriteFor(
            $context,
            CommandLine::make($context->options->update ? $manager->updateCommand() : $manager->installCommand())
                ->withTimeout($this->processConfig->installTimeoutSeconds),
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
        $output = trim($this->outputOf($exception));

        return $output !== '' ? $output : $exception->getMessage();
    }

    public static function progressLabel(BootOptions $options, array $parameters): string
    {
        return $options->update ? 'Updating frontend dependencies' : 'Installing frontend dependencies';
    }
}
