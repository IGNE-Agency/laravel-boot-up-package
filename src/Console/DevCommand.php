<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Closure;
use Igne\LaravelBootUp\Concerns\AnnouncesRun;
use Igne\LaravelBootUp\Concerns\ConfirmsPlan;
use Igne\LaravelBootUp\Concerns\GuardsAgainstFailures;
use Igne\LaravelBootUp\Concerns\RequiresUnix;
use Igne\LaravelBootUp\Config\ServeConfig;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Serve\DetachedDevRunner;
use Igne\LaravelBootUp\Serve\DevProcessRegistrar;
use Igne\LaravelBootUp\Serve\ServeRunner;
use Igne\LaravelBootUp\Tools\ToolInspector;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Foundation\Console\DevCommand as FrameworkDevCommand;
use Illuminate\Support\NodePackageManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Boots the application, then hands its dev processes to Laravel's own dev
 * command.
 *
 * Extending rather than replacing is the whole point: the terminal UI, its
 * tabs and stream modes, the crash restarts and every flag upstream adds
 * arrive here for free. boot-up contributes what the framework has no opinion
 * about — getting the project to a state where those processes can run at all.
 *
 * Left open rather than final so tests can replace delegateToFramework() and
 * assert what the boot registered without starting a real terminal UI.
 */
class DevCommand extends FrameworkDevCommand implements Isolatable
{
    use AnnouncesRun;
    use ConfirmsPlan;
    use GuardsAgainstFailures;
    use RequiresUnix;

    /** The multiplex terminal will not start below this. */
    private const string MULTIPLEX_NODE = '>=22.13';

    /** app:serve was renamed to dev; the old name keeps working for a release. */
    protected $aliases = ['app:serve'];

    protected $description = 'Boot everything the application needs, then run the dev processes';

    private ?ServeRunner $runner = null;

    private string $invokedAs = 'dev';

    public function __construct()
    {
        // The inherited signature is left untouched so upstream's options --
        // and whatever it adds next -- keep working. boot-up's are appended to
        // the definition it builds.
        parent::__construct();

        $this->addArgument('server', InputArgument::OPTIONAL, 'The development server to use (herd, sail, artisan, or any driver registered in boot-up.server.drivers)');

        // --seed gives up its -s shortcut: upstream claims it for --stream.
        $this->addOption('seed', null, InputOption::VALUE_NONE, 'Seed the database after migrating');
        $this->addOption('no-migrate', null, InputOption::VALUE_NONE, 'Skip running pending migrations');
        $this->addOption('fresh', null, InputOption::VALUE_NONE, 'Drop all tables and re-run every migration (asks first)');
        $this->addOption('update', 'u', InputOption::VALUE_NONE, 'Update dependencies instead of installing');
        $this->addOption('without-queue', null, InputOption::VALUE_NONE, 'Do not run a queue worker');
        $this->addOption('without-assets', null, InputOption::VALUE_NONE, 'Skip frontend dependencies and assets');
        $this->addOption('detach', 'd', InputOption::VALUE_NONE, 'Run the dev processes in the background instead of this terminal');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Run without the confirmation prompt');
    }

    protected function requiresUnix(): bool
    {
        return true;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->invokedAs = $input->getFirstArgument() ?? 'dev';

        if (! $this->runsOnThisPlatform()) {
            return self::FAILURE;
        }

        // parent::execute() keeps the Isolatable mutex, ManuallyFailedException
        // handling and container method-injection of handle().
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

        // Stored before anything can fail: onFailure() fires from
        // GuardsAgainstFailures OUTSIDE handle(), where re-resolving would
        // produce a fresh runner with a fresh, unbound reporter.
        $this->runner = $runner = $this->laravel->make(ServeRunner::class);
        $registrar = $this->laravel->make(DevProcessRegistrar::class);

        $this->warnWhenInvokedByItsOldName();

        $options = $this->devOptions();
        $plan = $runner->prepare($options, $this->argument('server'));

        if ($plan === null) {
            return self::FAILURE;
        }

        if (! $this->confirmPlan($plan, 'dev', $this->laravel->make(ServeConfig::class)->autoAccept)) {
            return $this->skip('Aborted — nothing was changed.');
        }

        $context = $runner->run(fn (array $signals, Closure $handler) => $this->trap($signals, $handler));

        // Only now are .env, composer.json and package.json final, so only now
        // can the gates decide which processes this project actually needs.
        $registrar->apply($context);

        if (! $options->follow) {
            $started = $this->laravel->make(DetachedDevRunner::class)->run();

            return $this->done($started === 0
                ? 'Application ready.'
                : 'Application ready — the dev processes run in the background.');
        }

        $this->warnWhenNodeCannotRunTheTerminal();

        // Ctrl+C belongs to the dev processes from here on. The boot-time trap
        // stands down so the multiplexer can shut its children down in order,
        // and teardown runs below once it has exited.
        $runner->handOff();

        $exitCode = $this->delegateToFramework($packageManager);

        $runner->tearDown();

        return $exitCode;
    }

    /**
     * The handoff to upstream, and the seam tests replace to assert what was
     * registered without starting a terminal UI.
     */
    protected function delegateToFramework(NodePackageManager $packageManager): int
    {
        return parent::handle($packageManager);
    }

    /**
     * Upstream ends this method with pcntl_exec, which replaces the PHP
     * process and would take boot-up's teardown with it: no stop-server
     * prompt, no cleared active-server record, no residual-state offer. The
     * command it builds is reused verbatim — only the process replacement is
     * dropped, so control comes back here when the terminal UI exits.
     *
     * runViaConcurrently() needs no such override; it already uses passthru.
     *
     * @param  array<int, array<string, mixed>>  $devCommands
     */
    #[\Override]
    protected function runViaMultiplex(array $devCommands, NodePackageManager $packageManager): int
    {
        passthru($packageManager->getExecCommand($this->buildMultiplexCommand($devCommands)), $exitCode);

        return $exitCode;
    }

    protected function onFailure(): void
    {
        $this->runner?->fail();
    }

    protected function failureHint(): void
    {
        terminal()->note('Background processes may still be running — clean up with: php artisan app:down');
    }

    private function devOptions(): ServeOptions
    {
        return new ServeOptions(
            seed: (bool) $this->option('seed'),
            migrate: ! $this->option('no-migrate'),
            update: (bool) $this->option('update'),
            withQueue: ! $this->option('without-queue'),
            withAssets: ! $this->option('without-assets'),
            fresh: (bool) $this->option('fresh'),
            follow: ! $this->option('detach') && $this->stdoutIsInteractive(),
        );
    }

    /**
     * Piped or redirected stdout (CI, scripts) cannot host a terminal UI, so
     * the processes run detached there instead.
     */
    protected function stdoutIsInteractive(): bool
    {
        return \defined('STDOUT') && \function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }

    private function warnWhenInvokedByItsOldName(): void
    {
        if ($this->invokedAs === 'app:serve') {
            terminal()->warning('app:serve is now `php artisan dev` — the old name still works but will be removed.');
        }
    }

    /**
     * The terminal UI is an npm package with its own Node floor. Upstream
     * reports its own failure if it cannot start; this only makes the reason
     * obvious, and points at the mode that needs no Node at all.
     */
    private function warnWhenNodeCannotRunTheTerminal(): void
    {
        $version = $this->laravel->make(ToolInspector::class)->installedVersion(Tool::NODE);

        if ($version === null || VersionConstraint::of(self::MULTIPLEX_NODE)->isSatisfiedBy($version)) {
            return;
        }

        terminal()->warning('The dev terminal needs Node '.self::MULTIPLEX_NODE." and this machine has {$version}. Upgrade Node, or run `php artisan dev --detach` to start the processes in the background instead.");
    }
}
