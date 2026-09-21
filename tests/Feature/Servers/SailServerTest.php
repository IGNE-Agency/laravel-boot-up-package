<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\SailConfig;
use Igne\LaravelBootUp\Config\SetupConfig;
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
use Igne\LaravelBootUp\Servers\Sail\SailCompose;
use Igne\LaravelBootUp\Servers\Sail\SailDatabaseCredentials;
use Igne\LaravelBootUp\Servers\Sail\SailDatabaseVolume;
use Igne\LaravelBootUp\Servers\Sail\SailPorts;
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
    ?string $envDirectory = null,
    bool $promptDbCredentials = true,
): SailServer {
    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($workDir.'/processes.json'),
        logDirectory: $workDir.'/logs',
    );

    $config = new SailConfig(
        readyTimeoutSeconds: $readyTimeout,
        dockerStartTimeoutSeconds: $dockerTimeout,
        promptDatabaseCredentials: $promptDbCredentials,
    );
    $sail = new Sail($runner);
    $compose = new SailCompose($runner, $sail);
    $envFile = new EnvFile($workDir.'/app/.env', $workDir.'/app/.env.example');
    $docker = new Docker($runner, new Poller, new Platform(OperatingSystem::Darwin), $config);
    $volume = new SailDatabaseVolume($compose, $docker, $envFile, app('config'));

    return new SailServer(
        docker: $docker,
        sail: $sail,
        aliasInstaller: $alias ?? new SailAliasInstaller(
            new ShellProfile($workDir.'/no-home', '/bin/zsh'),
            new SailConfig(manageAlias: false),
        ),
        poller: new Poller,
        laravelConfig: app('config'),
        envFile: $envFile,
        detector: new SailUpFailureDetector,
        ports: new SailPorts($compose),
        volume: $volume,
        credentials: new SailDatabaseCredentials(
            volume: $volume,
            envFile: $envFile,
            envRestore: envRestorePoint($envDirectory ?? $workDir),
            sailConfig: $config,
            setupConfig: new SetupConfig,
            laravelConfig: app('config'),
        ),
        config: $config,
    );
}

/**
 * A resolved compose config whose mysql service keeps its data on the
 * conventional named volume — enough for SailDatabaseVolume to pin the
 * Docker name down as `app_sail-mysql`.
 */
function sailComposeJson(): string
{
    return (string) json_encode([
        'name' => 'app',
        'services' => ['mysql' => [
            'volumes' => [['type' => 'volume', 'source' => 'sail-mysql', 'target' => '/var/lib/mysql']],
        ]],
        'volumes' => ['sail-mysql' => []],
    ]);
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

test('what sail:install rewrites in the .env is permanent — the teardown leaves it alone', function (): void {
    // sail:install points DB_* at its containers in place, and the database
    // container bakes those values into its volume at first start. Restoring
    // the old ones at teardown is exactly what desyncs .env from the volume,
    // so nothing is recorded.
    file_put_contents($this->basePath.'/.env', "DB_HOST=127.0.0.1\nDB_USERNAME=root\n");
    ProcessFaker::fake([
        'php artisan sail:install' => function () {
            file_put_contents($this->basePath.'/.env', "DB_HOST=mysql\nDB_USERNAME=sail\nREDIS_HOST=redis\n");

            return Process::result();
        },
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ]);

    sailServer($this->workDir, envDirectory: $this->basePath)->start(new BootContext(new BootOptions));

    envRestorePoint($this->basePath)->restore();

    $env = (string) file_get_contents($this->basePath.'/.env');

    expect($env)->toContain('DB_HOST=mysql')
        ->and($env)->toContain('DB_USERNAME=sail')
        ->and($env)->toContain('REDIS_HOST=redis');
});

test('a fresh database volume prompts for credentials and writes them before sail up', function (): void {
    Prompt::fake([Key::ENTER, Key::ENTER, Key::ENTER]);
    touch($this->basePath.'/docker-compose.yml');
    file_put_contents(
        $this->basePath.'/.env',
        "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_USERNAME=root\nDB_PASSWORD=secret\n",
    );

    ProcessFaker::fake([
        './vendor/bin/sail config --format json' => Process::result(output: sailComposeJson()),
        'docker volume inspect app_sail-mysql' => Process::result(exitCode: 1),
        // The volume is created FROM the .env at up-time, so the answers must
        // already be on disk when `sail up` runs.
        './vendor/bin/sail up -d' => function () {
            expect((string) file_get_contents($this->basePath.'/.env'))
                ->toContain('DB_USERNAME=sail')
                ->toContain('DB_DATABASE=app');

            return Process::result();
        },
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ]);

    sailServer($this->workDir, envDirectory: $this->basePath)->start(new BootContext(new BootOptions));

    Prompt::assertStrippedOutputContains('Sail is about to create the [mysql] database volume');

    $env = (string) file_get_contents($this->basePath.'/.env');

    // Enter accepted every default: a root username is normalized to `sail`
    // (the MySQL image refuses MYSQL_USER=root) and the password kept.
    expect($env)->toContain('DB_USERNAME=sail')
        ->and($env)->toContain('DB_DATABASE=app')
        ->and($env)->toContain('DB_PASSWORD=secret');
});

test('fresh-volume credentials survive the teardown restore, even over stale records', function (): void {
    // A boot from an older version recorded DB_USERNAME as session-scoped.
    // Once the volume is created with these values, that record must die
    // with the write — restoring it would desync .env from the volume.
    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=mysql\nDB_USERNAME=root\nDB_PASSWORD=secret\n");
    envRestorePoint($this->basePath)->around(function (): void {
        (new EnvFile($this->basePath.'/.env', $this->basePath.'/.env.example'))->set('DB_USERNAME', 'sail');
    });

    Prompt::fake([Key::ENTER, Key::ENTER, Key::ENTER]);
    touch($this->basePath.'/docker-compose.yml');
    ProcessFaker::fake([
        './vendor/bin/sail config --format json' => Process::result(output: sailComposeJson()),
        'docker volume inspect app_sail-mysql' => Process::result(exitCode: 1),
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ]);

    sailServer($this->workDir, envDirectory: $this->basePath)->start(new BootContext(new BootOptions));

    envRestorePoint($this->basePath)->restore();

    expect((string) file_get_contents($this->basePath.'/.env'))->toContain('DB_USERNAME=sail');
});

test('auto-accept keeps the .env credentials for a fresh volume without prompting', function (): void {
    // Prompt::fake() with no queued keys makes any prompt fail the test.
    touch($this->basePath.'/docker-compose.yml');
    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=mysql\nDB_USERNAME=root\nDB_PASSWORD=secret\n");

    ProcessFaker::fake([
        './vendor/bin/sail config --format json' => Process::result(output: sailComposeJson()),
        'docker volume inspect app_sail-mysql' => Process::result(exitCode: 1),
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ]);

    sailServer($this->workDir, envDirectory: $this->basePath)
        ->start(new BootContext(new BootOptions(autoAccept: true)));

    Prompt::assertStrippedOutputContains('Creating it with database');

    expect((string) file_get_contents($this->basePath.'/.env'))->toContain('DB_USERNAME=sail');
});

test('the fresh-volume prompt stands down when disabled, when the volume exists, and when the config is unreadable', function (): void {
    touch($this->basePath.'/docker-compose.yml');
    $env = "DB_CONNECTION=mysql\nDB_USERNAME=root\nDB_PASSWORD=secret\n";
    $fakes = [
        './vendor/bin/sail config --format json' => Process::result(output: sailComposeJson()),
        'docker volume inspect app_sail-mysql' => Process::result(exitCode: 1),
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ];

    // Kill switch off.
    file_put_contents($this->basePath.'/.env', $env);
    ProcessFaker::fake($fakes);
    sailServer($this->workDir, envDirectory: $this->basePath, promptDbCredentials: false)
        ->start(new BootContext(new BootOptions));
    expect((string) file_get_contents($this->basePath.'/.env'))->toContain('DB_USERNAME=root');

    // Volume already exists — its credentials were decided long ago.
    file_put_contents($this->basePath.'/.env', $env);
    ProcessFaker::fake(['docker volume inspect app_sail-mysql' => Process::result()] + $fakes);
    sailServer($this->workDir, envDirectory: $this->basePath)->start(new BootContext(new BootOptions));
    expect((string) file_get_contents($this->basePath.'/.env'))->toContain('DB_USERNAME=root');

    // Unreadable compose config — no volume to reason about, stand down.
    file_put_contents($this->basePath.'/.env', $env);
    ProcessFaker::fake(['./vendor/bin/sail config --format json' => Process::result(exitCode: 1)] + $fakes);
    sailServer($this->workDir, envDirectory: $this->basePath)->start(new BootContext(new BootOptions));
    expect((string) file_get_contents($this->basePath.'/.env'))->toContain('DB_USERNAME=root');
});

test('prepare is idempotent — start() after prepare() scaffolds and prompts once', function (): void {
    Prompt::fake([Key::ENTER, Key::ENTER, Key::ENTER]);
    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=mysql\nDB_PASSWORD=secret\n");

    ProcessFaker::fake([
        'php artisan sail:install' => function () {
            touch($this->basePath.'/docker-compose.yml');

            return Process::result();
        },
        './vendor/bin/sail config --format json' => Process::result(output: sailComposeJson()),
        'docker volume inspect app_sail-mysql' => Process::result(exitCode: 1),
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ]);

    $server = sailServer($this->workDir, envDirectory: $this->basePath);
    $context = new BootContext(new BootOptions);

    $server->prepare($context);
    // Three keys were queued and the prompt ran during prepare(); a second
    // prompt here would exhaust the fake and fail the test.
    $server->start($context);

    ProcessFaker::assertRanTimes('php artisan sail:install', 1);
});

test('resetDatabaseStorage recreates only the database volume', function (): void {
    touch($this->basePath.'/docker-compose.yml');
    ProcessFaker::fake([
        './vendor/bin/sail config --format json' => Process::result(output: sailComposeJson()),
        './vendor/bin/sail ps -q' => Process::result(output: "abc123\n"),
    ]);

    sailServer($this->workDir)->resetDatabaseStorage();

    ProcessFaker::assertRan('./vendor/bin/sail down');
    ProcessFaker::assertRan('docker volume rm app_sail-mysql');
    ProcessFaker::assertRan('./vendor/bin/sail up -d');
    // Targeted on purpose: `sail down -v` would also destroy redis,
    // meilisearch and minio data nobody consented to lose.
    ProcessFaker::assertDidntRun('*down -v*');
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

test('a taken host port surfaces as guidance, not a raw daemon error', function (): void {
    touch($this->basePath.'/compose.yaml');
    ProcessFaker::fake([
        './vendor/bin/sail up -d' => Process::result(
            exitCode: 1,
            errorOutput: 'Error response from daemon: ports are not available: exposing port TCP 0.0.0.0:3306 '
                .'-> 127.0.0.1:0: listen tcp 0.0.0.0:3306: bind: address already in use',
        ),
        // The compose config still resolves, so the remedy can name the
        // variable that moves the port.
        './vendor/bin/sail config --format json' => Process::result(output: (string) json_encode([
            'services' => ['mysql' => ['ports' => [
                ['mode' => 'ingress', 'target' => 3306, 'published' => '3306', 'protocol' => 'tcp'],
            ]]],
        ])),
    ]);

    expect(fn () => sailServer($this->workDir)->start(new BootContext(new BootOptions)))
        ->toThrow(ServerException::class, '3306 (mysql)');

    // A --build retry would only fail the same way.
    ProcessFaker::assertDidntRun('*--build*');
});

test('a port clash whose config cannot be read still explains itself', function (): void {
    touch($this->basePath.'/compose.yaml');
    ProcessFaker::fake([
        './vendor/bin/sail up -d' => Process::result(
            exitCode: 1,
            errorOutput: 'Bind for 0.0.0.0:80 failed: port is already allocated',
        ),
        './vendor/bin/sail config --format json' => Process::result(exitCode: 1),
    ]);

    expect(fn () => sailServer($this->workDir)->start(new BootContext(new BootOptions)))
        ->toThrow(ServerException::class, '80 (a container)');
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

    $command = sailServer($this->workDir)->devProcess(new BootContext(new BootOptions));

    expect($command?->toString())->toBe('./vendor/bin/sail logs --follow')
        ->and($command?->timeout)->toBeNull();
});
