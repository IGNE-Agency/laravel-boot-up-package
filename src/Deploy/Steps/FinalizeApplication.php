<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Runs the deploy config's finalize artisan commands (DeployConfig) host-side
 * (default: storage:link).
 */
#[Stage(BootStage::Finalize)]
#[Group('finalize')]
#[Label('Finalizing the application')]
final class FinalizeApplication implements Step
{
    public function __construct(
        private readonly DeployConfig $config,
        private readonly ProcessRunner $processes,
    ) {}

    public function handle(BootContext $context, Closure $next): mixed
    {
        foreach ($this->config->finalize as $command) {
            if ($this->storageLinkAlreadySatisfied($command)) {
                terminal()->note('Storage already linked.');

                continue;
            }

            terminal()->info("Running php artisan {$command}...");

            $this->processes->run(CommandLine::make("php artisan {$command}"));
        }

        return $next($context);
    }

    /**
     * A `storage:link` that would only re-create links which already exist is
     * skipped, so a repeat boot never surfaces Laravel's alarming
     * "The [public/storage] link already exists." ERROR. Any other command, a
     * forced relink (--force), or a genuinely missing link falls through to
     * the normal run. The link set is read from the same source that
     * `storage:link` itself uses.
     */
    private function storageLinkAlreadySatisfied(string $command): bool
    {
        $command = trim($command);

        if (! str_starts_with($command, 'storage:link') || str_contains($command, '--force')) {
            return false;
        }

        /** @var array<string, string> $links */
        $links = (array) config('filesystems.links', [public_path('storage') => storage_path('app/public')]);

        if ($links === []) {
            return false;
        }

        return collect($links)->keys()->every(fn (string $link): bool => file_exists($link));
    }
}
