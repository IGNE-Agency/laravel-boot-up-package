<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDeployTasks;
use Igne\LaravelBootUp\Data\DeployTask;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Deploy\DeployTaskRunner;
use Igne\LaravelBootUp\Deploy\Steps\RunDeployTasks;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-run-project-commands-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

function runProjectCommandsCommandOf(object $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : $process->command;
}

// Process::fake() must run before this resolves the Factory.
function bindRunProjectCommandsFixtures(string $dir): void
{
    app()->instance(DeployTaskRunner::class, new DeployTaskRunner(
        container: app(),
        processes: new ProcessRunner(
            processes: app(Factory::class),
            ledger: new ProcessLedger($dir.'/processes.json'),
            terminal: new NullTerminalLauncher,
            poller: new Poller,
            logDirectory: $dir.'/logs',
            runtimeDirectory: $dir.'/runtime',
        ),
        rewriter: new CommandRewriter,
        packageManagers: new PackageManagerSelector(
            new FrontendConfig(PackageManager::BUN, 'watch', 'background'),
            new PackageJson($dir.'/package.json'),
        ),
    ));

    app()->singleton(ProvidesDeployTasks::class, fn (): ProvidesDeployTasks => new class implements ProvidesDeployTasks
    {
        public function beforeDeploy(): array
        {
            return [DeployTask::artisan('pennant:purge')];
        }

        public function beforeMigrations(): array
        {
            return [DeployTask::artisan('config:clear')];
        }

        public function afterMigrations(): array
        {
            return [DeployTask::composer('dump-autoload')];
        }

        public function afterDeploy(): array
        {
            return [DeployTask::artisan('cache:warm')];
        }
    });
}

test("the pipeline ':after' parameter selects the after-migrations commands", function (): void {
    Process::fake(['*' => Process::result()]);
    bindRunProjectCommandsFixtures($this->dir);

    $context = new ServeContext(new ServeOptions);

    $result = app(Pipeline::class)
        ->send($context)
        ->through([RunDeployTasks::class.':after'])
        ->then(fn (ServeContext $passed): ServeContext => $passed);

    expect($result)->toBe($context);
    Process::assertRan(fn ($process): bool => runProjectCommandsCommandOf($process) === 'composer dump-autoload');
    Process::assertDidntRun(fn ($process): bool => runProjectCommandsCommandOf($process) === 'php artisan config:clear');
});

test("the ':before-deploy' parameter selects the before-deploy commands", function (): void {
    Process::fake(['*' => Process::result()]);
    bindRunProjectCommandsFixtures($this->dir);

    app(Pipeline::class)
        ->send(new ServeContext(new ServeOptions))
        ->through([RunDeployTasks::class.':before-deploy'])
        ->then(fn (ServeContext $passed): ServeContext => $passed);

    Process::assertRan(fn ($process): bool => runProjectCommandsCommandOf($process) === 'php artisan pennant:purge');
    Process::assertDidntRun(fn ($process): bool => runProjectCommandsCommandOf($process) === 'php artisan config:clear');
});

test('the phase defaults to before-migrations without a pipeline parameter', function (): void {
    Process::fake(['*' => Process::result()]);
    bindRunProjectCommandsFixtures($this->dir);

    app(Pipeline::class)
        ->send(new ServeContext(new ServeOptions))
        ->through([RunDeployTasks::class])
        ->then(fn (ServeContext $passed): ServeContext => $passed);

    Process::assertRan(fn ($process): bool => runProjectCommandsCommandOf($process) === 'php artisan config:clear');
    Process::assertDidntRun(fn ($process): bool => runProjectCommandsCommandOf($process) === 'composer dump-autoload');
});
