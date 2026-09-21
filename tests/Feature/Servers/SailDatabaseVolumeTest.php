<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\SailConfig;
use Igne\LaravelBootUp\Enums\OperatingSystem;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\Sail\Docker;
use Igne\LaravelBootUp\Servers\Sail\Sail;
use Igne\LaravelBootUp\Servers\Sail\SailCompose;
use Igne\LaravelBootUp\Servers\Sail\SailDatabaseVolume;
use Igne\LaravelBootUp\Services\Platform;
use Igne\LaravelBootUp\Services\Poller;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/boot-up-sail-volume-'.bin2hex(random_bytes(4));
    $this->basePath = $this->workDir.'/app';
    mkdir($this->basePath, 0755, true);
    $this->app->setBasePath($this->basePath);
    touch($this->basePath.'/docker-compose.yml');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function sailDatabaseVolume(string $workDir): SailDatabaseVolume
{
    $runner = new ProcessRunner(
        processes: app(Illuminate\Process\Factory::class),
        ledger: new ProcessLedger($workDir.'/processes.json'),
        logDirectory: $workDir.'/logs',
    );

    return new SailDatabaseVolume(
        compose: new SailCompose($runner, new Sail($runner)),
        docker: new Docker($runner, new Poller, new Platform(OperatingSystem::Darwin), new SailConfig),
        envFile: new EnvFile($workDir.'/app/.env', $workDir.'/app/.env.example'),
        laravelConfig: app('config'),
    );
}

/**
 * @param  array<string, mixed>  $services
 * @param  array<string, mixed>  $volumes
 */
function volumeComposeConfig(array $services, array $volumes = [], string $name = 'app'): string
{
    return (string) json_encode(['name' => $name, 'services' => $services, 'volumes' => $volumes]);
}

function databaseService(string $source): array
{
    return ['volumes' => [['type' => 'volume', 'source' => $source, 'target' => '/var/lib/data']]];
}

test('the volume name is the compose project prefix plus the service volume source', function (): void {
    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=mysql\n");
    ProcessFaker::fake([
        './vendor/bin/sail config --format json' => Process::result(output: volumeComposeConfig(
            ['mysql' => databaseService('sail-mysql')],
            ['sail-mysql' => []],
            name: 'igne-dashboard',
        )),
    ]);

    expect(sailDatabaseVolume($this->workDir)->name())->toBe('igne-dashboard_sail-mysql');
});

test('every database driver finds its own conventional service', function (): void {
    $config = fn (string $service, string $source): string => volumeComposeConfig(
        [$service => databaseService($source)],
        [$source => []],
    );

    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=mariadb\n");
    ProcessFaker::fake(['./vendor/bin/sail config *' => Process::result(output: $config('mariadb', 'sail-mariadb'))]);
    expect(sailDatabaseVolume($this->workDir)->name())->toBe('app_sail-mariadb');

    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=pgsql\n");
    ProcessFaker::fake(['./vendor/bin/sail config *' => Process::result(output: $config('pgsql', 'sail-pgsql'))]);
    expect(sailDatabaseVolume($this->workDir)->name())->toBe('app_sail-pgsql');
});

test('a renamed service is still found through DB_HOST', function (): void {
    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=mysql\nDB_HOST=database\n");
    ProcessFaker::fake([
        './vendor/bin/sail config *' => Process::result(output: volumeComposeConfig(
            ['database' => databaseService('db-data')],
            ['db-data' => []],
        )),
    ]);

    expect(sailDatabaseVolume($this->workDir)->name())->toBe('app_db-data');
});

test('an explicit volume name override wins over the project prefix', function (): void {
    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=mysql\n");
    ProcessFaker::fake([
        './vendor/bin/sail config *' => Process::result(output: volumeComposeConfig(
            ['mysql' => databaseService('sail-mysql')],
            ['sail-mysql' => ['name' => 'shared-mysql-data']],
        )),
    ]);

    expect(sailDatabaseVolume($this->workDir)->name())->toBe('shared-mysql-data');
});

test('the name is unknown for sqlite, bind mounts and unreadable configs', function (): void {
    // sqlite keeps no service at all.
    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=sqlite\n");
    ProcessFaker::fake();
    expect(sailDatabaseVolume($this->workDir)->name())->toBeNull();

    // A bind mount is not storage Docker names or owns.
    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=mysql\n");
    ProcessFaker::fake([
        './vendor/bin/sail config *' => Process::result(output: volumeComposeConfig([
            'mysql' => ['volumes' => [['type' => 'bind', 'source' => './data', 'target' => '/var/lib/mysql']]],
        ])),
    ]);
    expect(sailDatabaseVolume($this->workDir)->name())->toBeNull();

    // An unreadable config means "do not reason about it".
    ProcessFaker::fake(['./vendor/bin/sail config *' => Process::result(exitCode: 1)]);
    expect(sailDatabaseVolume($this->workDir)->name())->toBeNull();
});

test('freshness asks docker whether the volume exists', function (): void {
    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=mysql\n");
    $config = volumeComposeConfig(['mysql' => databaseService('sail-mysql')], ['sail-mysql' => []]);

    ProcessFaker::fake([
        './vendor/bin/sail config *' => Process::result(output: $config),
        'docker volume inspect app_sail-mysql' => Process::result(exitCode: 1),
    ]);
    expect(sailDatabaseVolume($this->workDir)->isFresh())->toBeTrue();

    ProcessFaker::fake([
        './vendor/bin/sail config *' => Process::result(output: $config),
        'docker volume inspect app_sail-mysql' => Process::result(),
    ]);
    expect(sailDatabaseVolume($this->workDir)->isFresh())->toBeFalse();
});

test('an unidentifiable volume is never fresh — there is nothing to decide for', function (): void {
    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=mysql\n");
    ProcessFaker::fake(['./vendor/bin/sail config *' => Process::result(exitCode: 1)]);

    expect(sailDatabaseVolume($this->workDir)->isFresh())->toBeFalse();
});

test('existedBeforeBoot defaults to the conservative answer and captures once', function (): void {
    file_put_contents($this->basePath.'/.env', "DB_CONNECTION=mysql\n");
    $config = volumeComposeConfig(['mysql' => databaseService('sail-mysql')], ['sail-mysql' => []]);
    ProcessFaker::fake([
        './vendor/bin/sail config *' => Process::result(output: $config),
        'docker volume inspect app_sail-mysql' => Process::result(exitCode: 1),
    ]);

    $volume = sailDatabaseVolume($this->workDir);

    // Never captured (a running server skips the capture): "existed" keeps
    // the recovery offer available without claiming this boot created it.
    expect($volume->existedBeforeBoot())->toBeTrue();

    $volume->captureExistedBeforeBoot();
    expect($volume->existedBeforeBoot())->toBeFalse();

    // The first capture wins — prepare() may run more than once.
    ProcessFaker::fake([
        './vendor/bin/sail config *' => Process::result(output: $config),
        'docker volume inspect app_sail-mysql' => Process::result(),
    ]);
    $volume->captureExistedBeforeBoot();
    expect($volume->existedBeforeBoot())->toBeFalse();
});
