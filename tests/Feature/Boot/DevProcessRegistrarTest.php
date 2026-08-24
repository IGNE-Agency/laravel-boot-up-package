<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\DevProcessRegistrar;
use Igne\LaravelBootUp\Boot\HorizonPresence;
use Igne\LaravelBootUp\Config\DevConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Config\HorizonConfig;
use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Config\ReverbConfig;
use Igne\LaravelBootUp\Config\SchedulerConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDevProcess;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Tests\Concerns\InteractsWithDevCommands;
use Illuminate\Config\Repository;
use Illuminate\Foundation\DevCommand;
use Illuminate\Foundation\DevCommands;
use Laravel\Prompts\Prompt;

uses(InteractsWithDevCommands::class);

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/boot-up-registrar-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);

    // A project with nothing installed and a buildable frontend, so each test
    // only has to state the one thing it cares about.
    file_put_contents($this->workDir.'/composer.json', json_encode(['require' => []]));
    file_put_contents($this->workDir.'/package.json', json_encode(['scripts' => ['dev' => 'vite']]));
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

/**
 * @param  list<string>  $requires  composer packages the project depends on
 */
function devRegistrar(
    string $dir,
    array $requires = [],
    ?QueueConfig $queue = null,
    ?ReverbConfig $reverb = null,
    ?SchedulerConfig $scheduler = null,
    ?FrontendConfig $frontend = null,
    ?DevConfig $dev = null,
    ?HorizonConfig $horizon = null,
    string $defaultConnection = 'database',
): DevProcessRegistrar {
    file_put_contents($dir.'/composer.json', json_encode([
        'require' => array_fill_keys($requires, '*'),
    ]));

    $frontend ??= new FrontendConfig(packageManager: PackageManager::Pnpm);
    $composerJson = new ComposerJson($dir.'/composer.json');
    $packageJson = new PackageJson($dir.'/package.json');

    return new DevProcessRegistrar(
        queueConfig: $queue ?? new QueueConfig,
        reverbConfig: $reverb ?? new ReverbConfig,
        schedulerConfig: $scheduler ?? new SchedulerConfig,
        frontendConfig: $frontend,
        devConfig: $dev ?? new DevConfig,
        horizonPresence: new HorizonPresence($horizon ?? new HorizonConfig, $composerJson),
        composerJson: $composerJson,
        packageJson: $packageJson,
        packageManagers: new PackageManagerSelector($frontend, $packageJson),
        envFile: new EnvFile($dir.'/.env', $dir.'/.env.example'),
        laravelConfig: new Repository(['queue' => ['default' => $defaultConnection]]),
        rewriter: new CommandRewriter,
    );
}

function devContext(?Server $server = null, bool $withQueue = true, bool $withAssets = true): BootContext
{
    return new BootContext(
        new BootOptions(withQueue: $withQueue, withAssets: $withAssets),
        $server,
    );
}

/**
 * A server that runs as one of the dev processes, like `php artisan serve`.
 */
function servingServer(string $command = 'php artisan serve --port=8000'): Server
{
    return new class($command) implements ProvidesDevProcess, Server
    {
        public function __construct(private readonly string $command) {}

        public function devProcess(BootContext $context): ?CommandLine
        {
            return CommandLine::make($this->command);
        }

        public function key(): string
        {
            return 'fixture';
        }

        public function label(): string
        {
            return 'Fixture server';
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function start(BootContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost:8000';
        }
    };
}

/**
 * A server that serves the application outside the run, the way Herd does.
 */
function externalServer(): Server
{
    return new class implements Server
    {
        public function key(): string
        {
            return 'external';
        }

        public function label(): string
        {
            return 'External server';
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function start(BootContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://app.test';
        }
    };
}

/**
 * A server that runs project commands somewhere else, the way Sail does.
 */
function containerServer(): Server
{
    return new class implements ProvidesDevProcess, RewritesCommands, Server
    {
        public function devProcess(BootContext $context): ?CommandLine
        {
            return CommandLine::make(['./vendor/bin/sail', 'logs', '--follow']);
        }

        public function commandRewrites(): CommandRewrites
        {
            return new CommandRewrites(
                replaces: ['php artisan' => 'artisan'],
                prefixes: ['php', 'artisan', 'pnpm'],
                prefix: './vendor/bin/sail',
            );
        }

        public function key(): string
        {
            return 'container';
        }

        public function label(): string
        {
            return 'Container server';
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function start(BootContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
}

it('replaces the default queue listener with a worker on the resolved connection', function (): void {
    devRegistrar($this->workDir, defaultConnection: 'redis')->apply(devContext(servingServer()));

    expect($this->devCommand('queue')['command'])->toBe('php artisan queue:work redis');
});

it('passes configured queue flags to the worker', function (): void {
    $queue = new QueueConfig(flags: ['--tries' => 3, '--verbose']);

    devRegistrar($this->workDir, queue: $queue)->apply(devContext(servingServer()));

    expect($this->devCommand('queue')['command'])->toBe('php artisan queue:work database --tries=3 --verbose');
});

it('reads the queue connection from .env over the loaded config', function (): void {
    file_put_contents($this->workDir.'/.env', "QUEUE_CONNECTION=sqs\n");

    devRegistrar($this->workDir, defaultConnection: 'database')->apply(devContext(servingServer()));

    expect($this->devCommand('queue')['command'])->toBe('php artisan queue:work sqs');
});

it('runs no queue worker when the connection is sync', function (): void {
    file_put_contents($this->workDir.'/.env', "QUEUE_CONNECTION=sync\n");

    devRegistrar($this->workDir)->apply(devContext(servingServer()));

    expect($this->devCommandNames())->not->toContain('queue');
});

it('runs no queue worker with --without-queue', function (): void {
    devRegistrar($this->workDir)->apply(devContext(servingServer(), withQueue: false));

    expect($this->devCommandNames())->not->toContain('queue');
});

it('runs no queue worker when disabled in configuration', function (): void {
    devRegistrar($this->workDir, queue: new QueueConfig(enabled: false))->apply(devContext(servingServer()));

    expect($this->devCommandNames())->not->toContain('queue');
});

it('starts horizon instead of the queue worker when horizon runs the queue', function (): void {
    devRegistrar($this->workDir, requires: ['laravel/horizon'])->apply(devContext(servingServer()));

    expect($this->devCommandNames())->toContain('horizon')->not->toContain('queue')
        ->and($this->devCommand('horizon')['command'])->toBe('php artisan horizon');
});

it('leaves horizon out of a project that does not use it', function (): void {
    devRegistrar($this->workDir)->apply(devContext(servingServer()));

    expect($this->devCommandNames())->not->toContain('horizon');
});

it('leaves horizon out when it is installed but disabled in configuration', function (): void {
    $registrar = devRegistrar($this->workDir, requires: ['laravel/horizon'], horizon: new HorizonConfig(enabled: false));

    $registrar->apply(devContext(servingServer()));

    expect($this->devCommandNames())->not->toContain('horizon')->toContain('queue');
});

it('starts reverb when the project depends on it', function (): void {
    devRegistrar($this->workDir, requires: ['laravel/reverb'])->apply(devContext(servingServer()));

    expect($this->devCommand('reverb')['command'])->toBe('php artisan reverb:start');
});

it('leaves reverb out when it is installed but disabled in configuration', function (): void {
    $registrar = devRegistrar($this->workDir, requires: ['laravel/reverb'], reverb: new ReverbConfig(enabled: false));

    $registrar->apply(devContext(servingServer()));

    expect($this->devCommandNames())->not->toContain('reverb');
});

it('leaves the scheduler out by default', function (): void {
    devRegistrar($this->workDir)->apply(devContext(servingServer()));

    expect($this->devCommandNames())->not->toContain('scheduler');
});

it('starts the scheduler when it is enabled', function (): void {
    $registrar = devRegistrar($this->workDir, scheduler: new SchedulerConfig(enabled: true));

    $registrar->apply(devContext(servingServer()));

    expect($this->devCommand('scheduler')['command'])->toBe('php artisan schedule:work');
});

it('runs the asset watcher through the package manager boot-up selected', function (): void {
    devRegistrar($this->workDir)->apply(devContext(servingServer()));

    expect($this->devCommand('vite')['command'])->toBe('pnpm run dev');
});

it('runs no asset watcher when package.json has no dev script', function (): void {
    file_put_contents($this->workDir.'/package.json', json_encode(['scripts' => ['build' => 'vite build']]));

    devRegistrar($this->workDir)->apply(devContext(servingServer()));

    expect($this->devCommandNames())->not->toContain('vite');
});

it('runs no asset watcher with --without-assets', function (): void {
    devRegistrar($this->workDir)->apply(devContext(servingServer(), withAssets: false));

    expect($this->devCommandNames())->not->toContain('vite');
});

it('runs no asset watcher when assets are built once for this run', function (): void {
    $frontend = new FrontendConfig(packageManager: PackageManager::Pnpm, assets: AssetMode::Build);

    devRegistrar($this->workDir, frontend: $frontend)->apply(devContext(servingServer()));

    expect($this->devCommandNames())->not->toContain('vite');
});

it('keeps the log process when pail is installed', function (): void {
    devRegistrar($this->workDir, requires: ['laravel/pail'])->apply(devContext(servingServer()));

    expect($this->devCommandNames())->toContain('logs');
});

it('drops the log process when pail is not installed', function (): void {
    devRegistrar($this->workDir)->apply(devContext(servingServer()));

    expect($this->devCommandNames())->not->toContain('logs');
});

it('drops the log process when disabled in configuration', function (): void {
    $registrar = devRegistrar($this->workDir, requires: ['laravel/pail'], dev: new DevConfig(logs: false));

    $registrar->apply(devContext(servingServer()));

    expect($this->devCommandNames())->not->toContain('logs');
});

it('runs the server the driver provides', function (): void {
    devRegistrar($this->workDir)->apply(devContext(servingServer('php artisan serve --port=9000')));

    expect($this->devCommand('server')['command'])->toBe('php artisan serve --port=9000');
});

it('carries no server process for a server that serves the application itself', function (): void {
    devRegistrar($this->workDir)->apply(devContext(externalServer()));

    expect($this->devCommandNames())->not->toContain('server');
});

it('rewrites every command for the server that booted', function (): void {
    devRegistrar($this->workDir)->apply(devContext(containerServer()));

    expect($this->devCommand('queue')['command'])->toBe('./vendor/bin/sail artisan queue:work database')
        ->and($this->devCommand('vite')['command'])->toBe('./vendor/bin/sail pnpm run dev')
        ->and($this->devCommand('server')['command'])->toBe('./vendor/bin/sail logs --follow');
});

it('leaves a server another package registered alone', function (): void {
    $this->seedDevCommand('php artisan octane:start --watch', 'server', DevCommand::PRIORITY_VENDOR);

    devRegistrar($this->workDir)->apply(devContext(servingServer()));

    expect($this->devCommand('server')['command'])->toBe('php artisan octane:start --watch');
});

it('takes over a process another package registered so it runs where the application runs', function (): void {
    // Horizon registers itself, but only boot-up knows this project runs in
    // containers and the command has to go through Sail to reach them.
    $this->seedDevCommand('php artisan horizon', 'horizon', DevCommand::PRIORITY_VENDOR);

    devRegistrar($this->workDir, requires: ['laravel/horizon'])->apply(devContext(containerServer()));

    expect($this->devCommand('horizon')['command'])->toBe('./vendor/bin/sail artisan horizon');
});

it('leaves a process the application registered alone', function (): void {
    $this->seedDevCommand('php artisan queue:work --queue=high', 'queue', DevCommand::PRIORITY_USERLAND);

    devRegistrar($this->workDir)->apply(devContext(containerServer()));

    expect($this->devCommand('queue')['command'])->toBe('php artisan queue:work --queue=high');
});

it('keeps a filter another package set when suppressing its own processes', function (): void {
    // Horizon excludes the queue worker exactly like this. Suppressing with
    // except() here would overwrite that and bring the worker back.
    DevCommands::except('queue');

    devRegistrar($this->workDir)->apply(devContext(servingServer()));

    expect($this->devCommandNames())->toBe(['server', 'vite']);
});

it('runs nothing at all when every process is gated off', function (): void {
    $registrar = devRegistrar($this->workDir, queue: new QueueConfig(enabled: false));

    $registrar->apply(devContext(externalServer(), withAssets: false));

    expect($this->devCommandNames())->toBe([]);
});

it('previews the processes that will run alongside registrations it does not own', function (): void {
    DevCommands::register('stripe listen --forward-to localhost', 'stripe');

    $preview = devRegistrar($this->workDir)->preview(devContext(servingServer()));

    expect($preview)->toBe(['server', 'queue', 'vite', 'stripe']);
});

it('previews without changing the registry', function (): void {
    $registrar = devRegistrar($this->workDir);

    $before = $this->devCommandNames();
    $registrar->preview(devContext(externalServer(), withQueue: false));

    expect($this->devCommandNames())->toBe($before);
});

it('previews the processes in the order their tabs will appear', function (): void {
    // Reverb self-registers from a provider, so it holds a slot ahead of
    // Laravel's defaults — the preview has to respect that, and boot-up's own
    // additions land after everything already registered.
    $this->seedDevCommand('php artisan reverb:start', 'reverb', DevCommand::PRIORITY_VENDOR);
    DevCommands::register('stripe listen --forward-to localhost', 'stripe');

    $registrar = devRegistrar($this->workDir, requires: ['laravel/reverb', 'laravel/pail']);
    $context = devContext(servingServer());

    $preview = $registrar->preview($context);
    $registrar->apply($context);

    expect($preview)->toBe($this->devCommandNames());
});

it('previews an appended process after the ones already registered', function (): void {
    $registrar = devRegistrar($this->workDir, scheduler: new SchedulerConfig(enabled: true));
    $context = devContext(servingServer());

    $preview = $registrar->preview($context);
    $registrar->apply($context);

    expect($preview)->toBe($this->devCommandNames())
        ->and($preview)->toBe(['server', 'queue', 'vite', 'scheduler']);
});

it('leaves a suppressed process out of the preview', function (): void {
    $registrar = devRegistrar($this->workDir, queue: new QueueConfig(enabled: false));
    $context = devContext(servingServer());

    $preview = $registrar->preview($context);
    $registrar->apply($context);

    expect($preview)->not->toContain('queue')
        ->and($preview)->toBe($this->devCommandNames());
});
