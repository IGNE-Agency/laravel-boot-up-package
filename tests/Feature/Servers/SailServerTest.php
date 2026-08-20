<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\SailConfig;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Enums\OperatingSystem;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\ShellProfile;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\Sail\Docker;
use Igne\LaravelBootUp\Servers\Sail\Sail;
use Igne\LaravelBootUp\Servers\Sail\SailAliasInstaller;
use Igne\LaravelBootUp\Servers\Sail\SailServer;
use Igne\LaravelBootUp\Servers\Sail\SailUpFailureDetector;
use Igne\LaravelBootUp\Services\Platform;
use Igne\LaravelBootUp\Services\Poller;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/boot-up-sail-'.bin2hex(random_bytes(4));
    $this->basePath = $this->workDir.'/app';
    mkdir($this->basePath, 0755, true);
    $this->app->setBasePath($this->basePath);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function sailServer(
    string $workDir,
    ?SailAliasInstaller $alias = null,
    int $dockerTimeout = 60,
    int $readyTimeout = 120,
): SailServer {
    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($workDir.'/processes.json'),
        logDirectory: $workDir.'/logs',
    );

    $config = new SailConfig(readyTimeoutSeconds: $readyTimeout, dockerStartTimeoutSeconds: $dockerTimeout);

    return new SailServer(
        docker: new Docker($runner, new Poller, new Platform(OperatingSystem::Darwin), $config),
        sail: new Sail($runner),
        aliasInstaller: $alias ?? new SailAliasInstaller(
            new ShellProfile($workDir.'/no-home', '/bin/zsh'),
            new SailConfig(manageAlias: false),
        ),
        poller: new Poller,
        laravelConfig: app('config'),
        envFile: new EnvFile($workDir.'/app/.env', $workDir.'/app/.env.example'),
        detector: new SailUpFailureDetector,
        config: $config,
    );
}

test('start boots docker, brings containers up and waits until they report', function (): void {
    touch($this->basePath.'/docker-compose.yml');
    ProcessFaker::fake([
        'docker info' => Process::sequence()
            ->push(Process::result(exitCode: 1))
            ->push(Process::result()),
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ]);

    sailServer($this->workDir)->start(new BootContext(new BootOptions));

    ProcessFaker::assertRan('open -a Docker');
    ProcessFaker::assertRan('./vendor/bin/sail up -d');
    ProcessFaker::assertRan('./vendor/bin/sail ps -q');
    ProcessFaker::assertDidntRun('php artisan sail:install');
});

test('start leaves docker alone when it is already running', function (): void {
    touch($this->basePath.'/docker-compose.yml');
    ProcessFaker::fake([
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ]);

    sailServer($this->workDir)->start(new BootContext(new BootOptions));

    ProcessFaker::assertDidntRun('open -a Docker');
    ProcessFaker::assertDidntRun('systemctl start docker');
});

test('start scaffolds sail when no compose file exists', function (): void {
    ProcessFaker::fake([
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ]);

    sailServer($this->workDir)->start(new BootContext(new BootOptions));

    ProcessFaker::assertRan('php artisan sail:install');
});

test('start throws when docker never becomes available', function (): void {
    touch($this->basePath.'/docker-compose.yml');
    ProcessFaker::fake([
        'docker info' => Process::result(exitCode: 1),
    ]);

    expect(fn () => sailServer($this->workDir, dockerTimeout: 0)->start(new BootContext(new BootOptions)))
        ->toThrow(ServerException::class, 'Docker did not become available');
});

test('a never-built app image is retried with --build', function (): void {
    touch($this->basePath.'/docker-compose.yml');
    ProcessFaker::fake([
        './vendor/bin/sail up -d --build' => Process::result(),
        './vendor/bin/sail up -d' => Process::result(
            exitCode: 1,
            errorOutput: 'failed to resolve reference "sail-8.5/app:latest": dial tcp: lookup sail-8.5: no such host',
        ),
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ]);

    sailServer($this->workDir)->start(new BootContext(new BootOptions));

    ProcessFaker::assertRan('./vendor/bin/sail up -d --build');
    Prompt::assertStrippedOutputContains("Sail's application image has not been built yet");
});

test('an unreachable registry throws guidance instead of retrying a build', function (): void {
    touch($this->basePath.'/docker-compose.yml');
    ProcessFaker::fake([
        './vendor/bin/sail up -d' => Process::result(
            exitCode: 1,
            errorOutput: 'dial tcp: lookup registry-1.docker.io: no such host',
        ),
    ]);

    expect(fn () => sailServer($this->workDir)->start(new BootContext(new BootOptions)))
        ->toThrow(ServerException::class, 'Docker could not reach its image registry');

    ProcessFaker::assertDidntRun('*--build*');
});

test('an unknown up failure bubbles as a process failure', function (): void {
    touch($this->basePath.'/docker-compose.yml');
    ProcessFaker::fake([
        './vendor/bin/sail up -d' => Process::result(
            exitCode: 1,
            errorOutput: 'yaml: line 12: mapping values are not allowed in this context',
        ),
    ]);

    expect(fn () => sailServer($this->workDir)->start(new BootContext(new BootOptions)))
        ->toThrow(ProcessFailedException::class);

    ProcessFaker::assertDidntRun('*--build*');
});

test('a build retry that hits an unreachable registry still throws guidance', function (): void {
    touch($this->basePath.'/docker-compose.yml');
    ProcessFaker::fake([
        './vendor/bin/sail up -d --build' => Process::result(
            exitCode: 1,
            errorOutput: 'Head "https://registry-1.docker.io/v2/library/ubuntu/manifests/24.04": i/o timeout',
        ),
        './vendor/bin/sail up -d' => Process::result(
            exitCode: 1,
            errorOutput: 'failed to resolve reference "sail-8.5/app:latest"',
        ),
    ]);

    expect(fn () => sailServer($this->workDir)->start(new BootContext(new BootOptions)))
        ->toThrow(ServerException::class, 'Docker could not reach its image registry');
});

test('start throws when containers never come up', function (): void {
    touch($this->basePath.'/docker-compose.yml');
    ProcessFaker::fake([
        './vendor/bin/sail ps -q' => Process::result(output: ''),
    ]);

    expect(fn () => sailServer($this->workDir, readyTimeout: 0)->start(new BootContext(new BootOptions)))
        ->toThrow(ServerException::class, 'Laravel Sail failed to start');
});

test('start offers the sail alias once the containers are up', function (): void {
    $home = $this->workDir.'/home';
    mkdir($home, 0755, true);
    file_put_contents($home.'/.zshrc', "# profile\n");

    Prompt::fake([Key::ENTER]);
    touch($this->basePath.'/docker-compose.yml');
    ProcessFaker::fake([
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ]);

    $alias = new SailAliasInstaller(
        new ShellProfile($home, '/bin/zsh'),
        new SailConfig(manageAlias: true),
    );

    sailServer($this->workDir, alias: $alias)->start(new BootContext(new BootOptions));

    expect((string) file_get_contents($home.'/.zshrc'))
        ->toContain("alias sail='[ -f sail ] && bash sail || bash vendor/bin/sail'");
});

test('isRunning reflects sail ps output', function (): void {
    ProcessFaker::fake(['./vendor/bin/sail ps -q' => Process::result(output: "abc123\n")]);
    expect(sailServer($this->workDir)->isRunning())->toBeTrue();

    ProcessFaker::fake(['./vendor/bin/sail ps -q' => Process::result(output: "  \n")]);
    expect(sailServer($this->workDir)->isRunning())->toBeFalse();

    ProcessFaker::fake(['./vendor/bin/sail ps -q' => Process::result(exitCode: 1, output: 'abc123')]);
    expect(sailServer($this->workDir)->isRunning())->toBeFalse();
});

test('stop runs sail down', function (): void {
    ProcessFaker::fake();

    sailServer($this->workDir)->stop();

    ProcessFaker::assertRan('./vendor/bin/sail down');
});

test('url prefers the .env, falls back to app.url, then to localhost', function (): void {
    ProcessFaker::fake();

    config()->set('app.url', 'http://sail.example');
    expect(sailServer($this->workDir)->url())->toBe('http://sail.example');

    file_put_contents($this->basePath.'/.env', "APP_URL=http://from-env.example\n");
    expect(sailServer($this->workDir)->url())->toBe('http://from-env.example');

    unlink($this->basePath.'/.env');
    config()->set('app.url', '');
    expect(sailServer($this->workDir)->url())->toBe('http://localhost');
});

test('has residual state only when sail is installed and configured', function (): void {
    ProcessFaker::fake();
    $server = sailServer($this->workDir);

    expect($server->hasResidualState())->toBeFalse();

    touch($this->basePath.'/docker-compose.yml');
    expect($server->hasResidualState())->toBeFalse();

    mkdir($this->basePath.'/vendor/bin', 0755, true);
    touch($this->basePath.'/vendor/bin/sail');
    expect($server->hasResidualState())->toBeTrue();

    // File checks only — residual detection must never talk to Docker.
    Process::assertNothingRan();
});

test('residual cleanup runs sail down', function (): void {
    ProcessFaker::fake();

    sailServer($this->workDir)->cleanUpResidualState();

    ProcessFaker::assertRan('./vendor/bin/sail down');
});

test('identity, tools and rewrites', function (): void {
    ProcessFaker::fake();
    $server = sailServer($this->workDir);
    $rewrites = $server->commandRewrites();

    expect($server->key())->toBe('sail')
        ->and($server->label())->toBe('Laravel Sail')
        ->and($server->requiredTools())->toBe([Tool::Docker])
        ->and($rewrites->replaces)->toBe(['php artisan' => 'artisan'])
        ->and($rewrites->prefixes)->toBe(['php', 'composer', 'yarn', 'npm', 'bun', 'pnpm', 'artisan', 'node'])
        ->and($rewrites->prefix)->toBe('./vendor/bin/sail');
});

test('devProcess follows the container logs as the server stream', function (): void {
    ProcessFaker::fake();

    $command = sailServer($this->workDir)->devProcess(new BootContext(new BootOptions(follow: true)));

    expect($command?->toString())->toBe('./vendor/bin/sail logs --follow')
        ->and($command?->timeout)->toBeNull();
});

test('devProcess carries no server stream for a detached run', function (): void {
    ProcessFaker::fake();

    expect(sailServer($this->workDir)->devProcess(new BootContext(new BootOptions(follow: false))))->toBeNull();
});
