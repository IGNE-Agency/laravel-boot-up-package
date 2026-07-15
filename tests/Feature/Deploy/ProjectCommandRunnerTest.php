<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Deploy\DeployException;
use Igne\LaravelBootstrap\Deploy\ProjectCommand;
use Igne\LaravelBootstrap\Deploy\ProjectCommandRunner;
use Igne\LaravelBootstrap\Deploy\ProvidesProjectCommands;
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
use Igne\LaravelBootstrap\Servers\CommandRewrites;
use Igne\LaravelBootstrap\Servers\Server;
use Igne\LaravelBootstrap\Support\Poller;
use Igne\LaravelBootstrap\Tools\Tool;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bootstrap-project-commands-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

// Process::fake() must run before this resolves the Factory.
function projectCommandRunner(string $dir): ProjectCommandRunner
{
    return new ProjectCommandRunner(
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
    );
}

function projectCommandOf(object $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : $process->command;
}

function bindProjectCommandProvider(): void
{
    app()->singleton(ProvidesProjectCommands::class, fn (): ProvidesProjectCommands => new class implements ProvidesProjectCommands
    {
        public function beforeMigrations(): array
        {
            return [
                ProjectCommand::artisan('wayfinder:generate --path=resources/js', 'Generating routes...'),
                ProjectCommand::packageManager('run zodgen'),
            ];
        }

        public function afterMigrations(): array
        {
            return [
                ProjectCommand::composer('dump-autoload --optimize'),
            ];
        }
    });
}

function projectCommandServer(CommandRewrites $rewrites): Server
{
    return new class($rewrites) implements Server
    {
        public function __construct(private readonly CommandRewrites $rewrites) {}

        public function key(): string
        {
            return 'sail';
        }

        public function label(): string
        {
            return 'Laravel Sail';
        }

        public function requiredTools(): array
        {
            return [Tool::DOCKER];
        }

        public function commandRewrites(): CommandRewrites
        {
            return $this->rewrites;
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function start(ServeContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
}

test('does nothing when no project command provider is bound', function (): void {
    Process::fake();

    projectCommandRunner($this->dir)->run('before', new ServeContext(new ServeOptions));

    Process::assertNothingRan();
});

test('runs the before-phase commands as artisan and package manager token arrays', function (): void {
    Process::fake(['*' => Process::result()]);
    bindProjectCommandProvider();

    projectCommandRunner($this->dir)->run('before', new ServeContext(new ServeOptions));

    Process::assertRan(fn ($process): bool => projectCommandOf($process) === 'php artisan wayfinder:generate --path=resources/js');
    Process::assertRan(fn ($process): bool => projectCommandOf($process) === 'bun run zodgen');
    Process::assertDidntRun(fn ($process): bool => str_starts_with(projectCommandOf($process), 'composer'));
    Prompt::assertStrippedOutputContains('Generating routes...');
});

test('runs the after-phase commands as composer token arrays', function (): void {
    Process::fake(['*' => Process::result()]);
    bindProjectCommandProvider();

    projectCommandRunner($this->dir)->run('after', new ServeContext(new ServeOptions));

    Process::assertRan(fn ($process): bool => projectCommandOf($process) === 'composer dump-autoload --optimize');
    Process::assertDidntRun(fn ($process): bool => str_starts_with(projectCommandOf($process), 'php artisan'));
});

test('rewrites commands through the active server', function (): void {
    Process::fake(['*' => Process::result()]);
    bindProjectCommandProvider();

    $server = projectCommandServer(new CommandRewrites(
        replaces: ['php artisan' => './vendor/bin/sail artisan'],
        prefixes: ['composer', 'bun'],
        prefix: './vendor/bin/sail',
    ));

    projectCommandRunner($this->dir)->run('before', new ServeContext(new ServeOptions, $server));

    Process::assertRan(fn ($process): bool => projectCommandOf($process) === './vendor/bin/sail artisan wayfinder:generate --path=resources/js');
    Process::assertRan(fn ($process): bool => projectCommandOf($process) === './vendor/bin/sail bun run zodgen');
});

test('a failing project command aborts the boot with a DeployException', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'boom')]);
    bindProjectCommandProvider();

    expect(fn () => projectCommandRunner($this->dir)->run('before', new ServeContext(new ServeOptions)))
        ->toThrow(DeployException::class, 'wayfinder:generate');
});

test('an unknown phase is rejected', function (): void {
    Process::fake();
    bindProjectCommandProvider();

    expect(fn () => projectCommandRunner($this->dir)->run('during', new ServeContext(new ServeOptions)))
        ->toThrow(InvalidArgumentException::class, 'during');
});
