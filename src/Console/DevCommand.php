<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Boot\DetachedDevRunner;
use Igne\LaravelBootUp\Boot\DevProcessRegistrar;
use Igne\LaravelBootUp\Boot\DevSession;
use Igne\LaravelBootUp\Boot\ProjectReadiness;
use Igne\LaravelBootUp\Concerns\AnnouncesRun;
use Igne\LaravelBootUp\Concerns\GuardsAgainstFailures;
use Igne\LaravelBootUp\Concerns\RequiresUnix;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\ServerSelector;
use Igne\LaravelBootUp\Tools\ToolInspector;
use Illuminate\Foundation\Console\DevCommand as FrameworkDevCommand;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\NodePackageManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs Laravel's dev command over the processes this project actually needs.
 *
 * This is the command that gets run every day, so it does as little as
 * possible before the terminal UI appears: a handful of filesystem reads to
 * confirm the project is set up, the server it was set up with, and the gates
 * that decide which processes to register. Everything that takes real work
 * belongs to app:setup, which this command points at when the project is not
 * ready.
 *
 * Extending rather than replacing is the whole point: the terminal UI, its
 * tabs and stream modes, the crash restarts and every flag upstream adds
 * arrive here for free. boot-up contributes only the process list.
 *
 * Left open rather than final so tests can replace delegateToFramework() and
 * assert what was registered without starting a real terminal UI.
 *
 * @phpstan-import-type DevCommandArray from \Illuminate\Foundation\DevCommands
 */
class DevCommand extends FrameworkDevCommand
{
    use AnnouncesRun;
    use GuardsAgainstFailures;
    use RequiresUnix;

    /** The multiplex terminal will not start below this. */
    private const string MULTIPLEX_NODE = '>=22.13';

    protected $description = 'Run the dev processes this project needs';

    public function __construct()
    {
        // The inherited signature is left untouched so upstream's options --
        // and whatever it adds next -- keep working. boot-up's are appended to
        // the definition it builds.
        parent::__construct();

        $this->addArgument('server', InputArgument::OPTIONAL, 'Override the server app:setup recorded (herd, sail, artisan, or any driver registered in boot-up.server.drivers)');

        $this->addOption('without-queue', null, InputOption::VALUE_NONE, 'Do not run a queue worker');
        $this->addOption('without-assets', null, InputOption::VALUE_NONE, 'Do not run an asset watcher');
        $this->addOption('detach', 'd', InputOption::VALUE_NONE, 'Run the dev processes in the background instead of this terminal');
    }

    /**
     * Windows would route upstream's handle() to runViaConcurrently(), and the
     * commands boot-up registers are Unix shell lines regardless.
     */
    protected function requiresUnix(): bool
    {
        return true;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! $this->runsOnThisPlatform()) {
            return self::FAILURE;
        }

        // parent::execute() keeps ManuallyFailedException handling and
        // container method-injection of handle().
        return $this->guardAgainstFailures(fn (): int => parent::execute($input, $output));
    }

    /**
     * The signature is upstream's, so the parameter has to be too — anything
     * else would be an incompatible override. The collaborators boot-up needs
     * come out of the container instead.
     */
    public function handle(NodePackageManager $packageManager): int
    {
        if ($this->isProhibited()) {
            return self::FAILURE;
        }

        $options = $this->devOptions();

        $problems = $this->laravel->make(ProjectReadiness::class)->problems($options);

        if ($problems !== []) {
            return $this->notReady($problems);
        }

        $server = $this->argument('server');
        $server = $this->laravel->make(ServerSelector::class)->remembered(\is_string($server) ? $server : null);

        if ($server === null) {
            return $this->notReady(['No development server is set up for this project.']);
        }

        $this->laravel->make(DevProcessRegistrar::class)->apply(new BootContext($options, $server));

        if (DevCommands::commands() === []) {
            return $this->skip('Nothing to run — every dev process is gated off for this project.');
        }

        if ($this->option('detach')) {
            $this->laravel->make(DetachedDevRunner::class)->run();

            return $this->done('The dev processes run in the background — stop them with: php artisan app:down');
        }

        $this->warnWhenNodeCannotRunTheTerminal();

        return $this->delegateToFramework($packageManager);
    }

    /**
     * The handoff to upstream, and the seam tests replace to assert what was
     * registered without starting a terminal UI.
     *
     * Upstream ends in pcntl_exec, which replaces this process: the terminal
     * UI owns the terminal outright, with no PHP parent left in the process
     * group to intercept its keys or its Ctrl+C. That is what makes the
     * visuals and the quit behaviour identical to plain `php artisan dev` —
     * and the one run that cannot afford it, the session app:setup claims,
     * is handled in runViaMultiplex() below.
     */
    protected function delegateToFramework(NodePackageManager $packageManager): int
    {
        return parent::handle($packageManager);
    }

    /**
     * The one thing a claimed session changes: the terminal UI runs as a
     * child of this process rather than replacing it.
     *
     * app:setup claims the session because it has a server to stop once the
     * terminal quits, and pcntl_exec would leave no PHP behind to stop it.
     * Everything the user sees is the same command line upstream would have
     * exec'd, handed the same terminal.
     *
     * @param  DevCommandArray[]  $devCommands
     */
    #[\Override]
    protected function runViaMultiplex(array $devCommands, NodePackageManager $packageManager): int
    {
        if (! $this->laravel->make(DevSession::class)->isClaimed()) {
            return parent::runViaMultiplex($devCommands, $packageManager);
        }

        $command = $packageManager->getExecCommand($this->buildMultiplexCommand($devCommands));

        // Through a shell, not argv: getExecCommand() returns a quoted shell
        // line, and one dev process per tab is quoted inside it.
        return $this->laravel->make(ProcessRunner::class)->runInTerminal(
            CommandLine::make(['sh', '-c', $command])->withTimeout(null),
        );
    }

    protected function failureHint(): void
    {
        terminal()->note('Set the project up with: php artisan app:setup');
    }

    /**
     * Nothing was started, so there is nothing to clean up — only something
     * to do next.
     *
     * @param  list<string>  $problems
     */
    private function notReady(array $problems): int
    {
        terminal()->summary(
            'This project is not ready to run its dev processes',
            $problems,
            'Set it up with: php artisan app:setup',
        );

        return self::FAILURE;
    }

    private function devOptions(): BootOptions
    {
        return new BootOptions(
            withQueue: ! $this->option('without-queue'),
            withAssets: ! $this->option('without-assets'),
        );
    }

    /**
     * The terminal UI is an npm package with its own Node floor. Upstream
     * reports its own failure if it cannot start; this only makes the reason
     * obvious, and points at the mode that needs no Node at all.
     */
    private function warnWhenNodeCannotRunTheTerminal(): void
    {
        $version = $this->laravel->make(ToolInspector::class)->installedVersion(Tool::Node);

        if ($version === null || VersionConstraint::of(self::MULTIPLEX_NODE)->isSatisfiedBy($version)) {
            return;
        }

        terminal()->warning('The dev terminal needs Node '.self::MULTIPLEX_NODE." and this machine has {$version}. Upgrade Node, or run `php artisan dev --detach` to start the processes in the background instead.");
    }
}
