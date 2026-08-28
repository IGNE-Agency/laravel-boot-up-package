<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

use Igne\LaravelBootUp\Config\DevConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Config\ReverbConfig;
use Igne\LaravelBootUp\Config\SchedulerConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDevProcess;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\DevProcess;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\BuiltInProcess;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Illuminate\Contracts\Config\Repository;

/**
 * Decides which processes the dev command runs.
 *
 * Laravel registers four processes by default and offers no way to make one
 * conditional, so a project without Pail still gets a `logs` process and a
 * project running Horizon still gets a plain queue worker beside it. This
 * class applies the detection boot-up already does — is Horizon installed, is
 * the queue connection sync, does package.json have a dev script — and
 * DevProcessRegistrar folds the outcome into Laravel's registry.
 *
 * Every gate reads the project as it is on disk right now, which is why `dev`
 * refuses to run against a project app:up has not finished: gates reading a
 * .env that does not exist yet would decide against processes the project
 * needs.
 */
final class DevProcessDecisions
{
    public function __construct(
        private readonly QueueConfig $queueConfig,
        private readonly ReverbConfig $reverbConfig,
        private readonly SchedulerConfig $schedulerConfig,
        private readonly FrontendConfig $frontendConfig,
        private readonly DevConfig $devConfig,
        private readonly HorizonPresence $horizonPresence,
        private readonly ComposerJson $composerJson,
        private readonly PackageJson $packageJson,
        private readonly PackageManagerSelector $packageManagers,
        private readonly EnvFile $envFile,
        private readonly Repository $laravelConfig,
    ) {}

    /**
     * @return list<DevProcess>
     */
    public function for(BootContext $context): array
    {
        return [
            $this->server($context),
            $this->queue($context),
            $this->horizon(),
            $this->reverb(),
            $this->scheduler(),
            $this->assets($context),
            $this->logs(),
        ];
    }

    /**
     * Herd and friends serve the application themselves, so they carry no
     * process here and the framework's own `php artisan serve` default would
     * bind a second port for nothing.
     */
    private function server(BootContext $context): DevProcess
    {
        $server = $context->server;

        if (! $server instanceof ProvidesDevProcess) {
            return DevProcess::skip(BuiltInProcess::Server->value);
        }

        $command = $server->devProcess($context);

        return $command === null
            ? DevProcess::skip(BuiltInProcess::Server->value)
            : DevProcess::start(BuiltInProcess::Server->value, $command);
    }

    private function queue(BootContext $context): DevProcess
    {
        $connection = $this->connection();

        $reason = match (true) {
            ! $context->options->withQueue => 'Queue worker skipped (--without-queue).',
            ! $this->queueConfig->enabled => 'Queue worker disabled in configuration — skipping.',
            $this->horizonPresence->managesQueue() => 'laravel/horizon manages the queue — skipping queue:work.',
            $connection === 'sync' => 'Queue connection is sync — no worker needed.',
            default => null,
        };

        if ($reason !== null) {
            return DevProcess::skip(BuiltInProcess::Queue->value, $reason);
        }

        return DevProcess::start(BuiltInProcess::Queue->value, CommandLine::make(['php', 'artisan', 'queue:work', $connection])
            ->withOptions($this->queueConfig->flags));
    }

    private function horizon(): DevProcess
    {
        return $this->horizonPresence->managesQueue()
            ? DevProcess::start(BuiltInProcess::Horizon->value, CommandLine::make(['php', 'artisan', 'horizon']))
            : DevProcess::skip(BuiltInProcess::Horizon->value);
    }

    private function reverb(): DevProcess
    {
        return $this->reverbConfig->enabled && $this->composerJson->requires('laravel/reverb')
            ? DevProcess::start(BuiltInProcess::Reverb->value, CommandLine::make(['php', 'artisan', 'reverb:start']))
            : DevProcess::skip(BuiltInProcess::Reverb->value);
    }

    private function scheduler(): DevProcess
    {
        return $this->schedulerConfig->enabled
            ? DevProcess::start(BuiltInProcess::Scheduler->value, CommandLine::make(['php', 'artisan', 'schedule:work']))
            : DevProcess::skip(BuiltInProcess::Scheduler->value);
    }

    private function assets(BootContext $context): DevProcess
    {
        // Build mode skips quietly: the BuildAssets step speaks for that run.
        if ($this->frontendConfig->assets === AssetMode::Build) {
            return DevProcess::skip(BuiltInProcess::Vite->value);
        }

        $reason = match (true) {
            ! $context->options->withAssets => 'Assets skipped (--without-assets).',
            $this->frontendConfig->assets === AssetMode::Skip => 'Assets disabled in configuration — skipping.',
            ! $this->packageJson->exists() => 'No package.json found — skipping assets.',
            ! $this->packageJson->hasScript('dev') => "package.json has no 'dev' script — skipping the asset watcher.",
            default => null,
        };

        if ($reason !== null) {
            return DevProcess::skip(BuiltInProcess::Vite->value, $reason);
        }

        // Registered rather than left to the framework: boot-up installed the
        // dependencies with the package manager its own selector picked, which
        // honors the config override and package.json's engines sentinel.
        return DevProcess::start(BuiltInProcess::Vite->value, CommandLine::make($this->packageManagers->selected()->runCommand('dev')));
    }

    private function logs(): DevProcess
    {
        return match (true) {
            ! $this->devConfig->logs => DevProcess::skip(BuiltInProcess::Logs->value),
            ! $this->composerJson->requires('laravel/pail') => DevProcess::skip(BuiltInProcess::Logs->value),
            default => DevProcess::keep(BuiltInProcess::Logs->value),
        };
    }

    /**
     * Read fresh every time: the boot pipeline may have written .env between
     * the plan summary and the point where processes are registered.
     */
    private function connection(): string
    {
        return $this->envFile->valueOr('QUEUE_CONNECTION', (string) $this->laravelConfig->get('queue.default'));
    }
}
