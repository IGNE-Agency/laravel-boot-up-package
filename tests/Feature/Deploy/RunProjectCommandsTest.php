<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Deploy\ProjectCommand;
use Igne\LaravelBootstrap\Deploy\ProjectCommandRunner;
use Igne\LaravelBootstrap\Deploy\ProvidesProjectCommands;
use Igne\LaravelBootstrap\Deploy\Steps\RunProjectCommands;
use Igne\LaravelBootstrap\Frontend\FrontendConfig;
use Igne\LaravelBootstrap\Frontend\PackageJson;
use Igne\LaravelBootstrap\Frontend\PackageManager;
use Igne\LaravelBootstrap\Frontend\PackageManagerSelector;
use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\Terminal\NullTerminal;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\ServeOptions;
use Igne\LaravelBootstrap\Servers\CommandRewriter;
use Igne\LaravelBootstrap\Support\Poller;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bootstrap-run-project-commands-'.bin2hex(random_bytes(4));
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
    app()->instance(ProjectCommandRunner::class, new ProjectCommandRunner(
        container: app(),
        processes: new ProcessRunner(
            processes: app(Factory::class),
            ledger: new ProcessLedger($dir.'/processes.json'),
            terminal: new NullTerminal,
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

    app()->singleton(ProvidesProjectCommands::class, fn (): ProvidesProjectCommands => new class implements ProvidesProjectCommands
    {
        public function beforeMigrations(): array
        {
            return [ProjectCommand::artisan('config:clear')];
        }

        public function afterMigrations(): array
        {
            return [ProjectCommand::composer('dump-autoload')];
        }
    });
}

test("the pipeline ':after' parameter selects the after-migrations commands", function (): void {
    Process::fake(['*' => Process::result()]);
    bindRunProjectCommandsFixtures($this->dir);

    $context = new ServeContext(new ServeOptions);

    $result = app(Pipeline::class)
        ->send($context)
        ->through([RunProjectCommands::class.':after'])
        ->then(fn (ServeContext $passed): ServeContext => $passed);

    expect($result)->toBe($context);
    Process::assertRan(fn ($process): bool => runProjectCommandsCommandOf($process) === 'composer dump-autoload');
    Process::assertDidntRun(fn ($process): bool => runProjectCommandsCommandOf($process) === 'php artisan config:clear');
});

test('the phase defaults to before-migrations without a pipeline parameter', function (): void {
    Process::fake(['*' => Process::result()]);
    bindRunProjectCommandsFixtures($this->dir);

    app(Pipeline::class)
        ->send(new ServeContext(new ServeOptions))
        ->through([RunProjectCommands::class])
        ->then(fn (ServeContext $passed): ServeContext => $passed);

    Process::assertRan(fn ($process): bool => runProjectCommandsCommandOf($process) === 'php artisan config:clear');
    Process::assertDidntRun(fn ($process): bool => runProjectCommandsCommandOf($process) === 'composer dump-autoload');
});
