<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Config\DevConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Config\ReverbConfig;
use Igne\LaravelBootUp\Config\SchedulerConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDevProcess;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\DevProcess;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\BuiltInProcess;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Workers\HorizonPresence;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\DevCommand;
use Illuminate\Foundation\DevCommands;

/**
 * Decides which processes the dev command runs, and folds that decision into
 * Laravel's registry.
 *
 * Laravel registers four processes by default and offers no way to make one
 * conditional, so a project without Pail still gets a `logs` process and a
 * project running Horizon still gets a plain queue worker beside it. This
 * class applies the detection boot-up already does — is Horizon installed, is
 * the queue connection sync, does package.json have a dev script — and
 * rewrites each command for the server that booted, so under Sail they run in
 * the container rather than on the host.
 *
 * It runs after the boot pipeline, once .env, composer.json and package.json
 * are final: a first boot creates .env, and gates that read it would otherwise
 * decide against a file that did not exist yet.
 */
final class DevProcessRegistrar
{
    /**
     * only() with no names means "no filter", so a run where every process is
     * gated off needs a name that matches nothing to say so.
     */
    private const string MATCHES_NOTHING = '__boot-up:none__';

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
        private readonly CommandRewriter $rewriter,
    ) {}

    /**
     * The names that will run, for the plan summary. Best effort: it is read
     * before the pipeline has created .env or installed dependencies, so it
     * describes the project as it stands right now.
     *
     * @return list<string>
     */
    public function preview(ServeContext $context): array
    {
        $running = [];

        foreach ($this->decisions($context) as $process) {
            if ($process->runs) {
                $running[] = $process->name;
            }
        }

        // Whatever the application and its packages registered for themselves.
        $registered = array_diff(array_column(DevCommands::commands(), 'name'), BuiltInProcess::names());

        return [...$running, ...array_values($registered)];
    }

    /**
     * Register boot-up's processes and filter out the ones this run does not
     * need.
     */
    public function apply(ServeContext $context): void
    {
        $suppressed = [];

        foreach ($this->decisions($context) as $process) {
            if (! $process->runs) {
                $suppressed[] = $process->name;

                if ($process->skipReason !== null) {
                    terminal()->note($process->skipReason);
                }

                continue;
            }

            if ($process->command === null || $this->isClaimed($process->name)) {
                continue;
            }

            DevCommands::register(
                $this->rewriter->rewriteFor($context, $process->command)->toString(),
                $process->name,
            );
        }

        $this->suppress($suppressed);
    }

    /**
     * @return list<DevProcess>
     */
    private function decisions(ServeContext $context): array
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
    private function server(ServeContext $context): DevProcess
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

    private function queue(ServeContext $context): DevProcess
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

    private function assets(ServeContext $context): DevProcess
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
     * Whether someone else already owns this name with more authority than
     * boot-up has.
     */
    private function isClaimed(string $name): bool
    {
        foreach (DevCommands::commands() as $command) {
            if ($command['name'] === $name) {
                return $this->outranksBootUp($name, $command['priority']);
            }
        }

        return false;
    }

    /**
     * The application always wins — someone who writes a registration into
     * their own provider means it.
     *
     * A package only wins for `server`: Octane registers itself there, and
     * `octane:start --watch` is the better server for a project that installed
     * it. Everywhere else boot-up replaces a package's registration, because
     * it is the only party that knows the command has to run inside Sail's
     * containers rather than on the host.
     */
    private function outranksBootUp(string $name, int $priority): bool
    {
        return $name === BuiltInProcess::Server->value
            ? $priority > DevCommand::PRIORITY_DEFAULT
            : $priority > DevCommand::PRIORITY_VENDOR;
    }

    /**
     * Take the gated-off processes out of the run.
     *
     * only() and except() both overwrite the whole filter and neither can be
     * read back, so calling except() here would discard what another package
     * asked for — Horizon excludes the queue worker this way. Filtering to the
     * names that survive instead keeps those earlier decisions, because
     * commands() has already applied them.
     *
     * @param  list<string>  $suppressed
     */
    private function suppress(array $suppressed): void
    {
        if ($suppressed === []) {
            return;
        }

        $remaining = array_values(array_diff(
            array_column(DevCommands::commands(), 'name'),
            $suppressed,
        ));

        DevCommands::only(...($remaining === [] ? [self::MATCHES_NOTHING] : $remaining));
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
