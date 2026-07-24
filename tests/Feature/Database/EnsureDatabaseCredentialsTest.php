<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Database\Steps\EnsureDatabaseCredentials;
use Igne\LaravelBootUp\Environment\EnvFile;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-db-credentials-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    $this->envFile = new EnvFile($this->dir.'/.env', $this->dir.'/.env.example');

    $this->step = fn (?DatabaseConfig $config = null): EnsureDatabaseCredentials => new EnsureDatabaseCredentials(
        $config ?? new DatabaseConfig,
        $this->envFile,
        config(),
    );

    $this->sailServer = new class implements Server
    {
        public function key(): string
        {
            return 'sail';
        }

        public function label(): string
        {
            return 'Laravel Sail';
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
});

afterEach(function (): void {
    if (is_dir($this->dir)) {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
});

test('prompting disabled by config leaves the env file alone', function (): void {
    Prompt::fake();
    file_put_contents($this->dir.'/.env', "DB_CONNECTION=mysql\n");

    $context = new ServeContext(new ServeOptions);
    $config = new DatabaseConfig(promptMissingCredentials: false);

    $result = ($this->step)($config)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context)
        ->and(file_get_contents($this->dir.'/.env'))->toBe("DB_CONNECTION=mysql\n");
});

test('sqlite needs no credentials and short-circuits', function (): void {
    Prompt::fake();
    file_put_contents($this->dir.'/.env', "DB_CONNECTION=sqlite\n");

    $context = new ServeContext(new ServeOptions);

    $result = ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context)
        ->and(file_get_contents($this->dir.'/.env'))->toBe("DB_CONNECTION=sqlite\n");
});

test('complete credentials prompt nothing, even with an empty password', function (): void {
    Prompt::fake();

    $env = implode("\n", [
        'DB_CONNECTION=mysql',
        'DB_HOST=127.0.0.1',
        'DB_PORT=3306',
        'DB_DATABASE=igne',
        'DB_USERNAME=root',
        'DB_PASSWORD=',
    ])."\n";
    file_put_contents($this->dir.'/.env', $env);

    $context = new ServeContext(new ServeOptions);

    $result = ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context)
        ->and(file_get_contents($this->dir.'/.env'))->toBe($env);
});

test('missing keys are prompted with host defaults, landing in .env and config', function (): void {
    Prompt::fake([Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);
    file_put_contents($this->dir.'/.env', "DB_CONNECTION=mysql\n");

    ($this->step)()->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    $database = Str::slug(basename(base_path()), '_');

    expect($this->envFile->get('DB_HOST'))->toBe('127.0.0.1')
        ->and($this->envFile->get('DB_PORT'))->toBe('3306')
        ->and($this->envFile->get('DB_DATABASE'))->toBe($database)
        ->and($this->envFile->get('DB_USERNAME'))->toBe('root')
        ->and($this->envFile->has('DB_PASSWORD'))->toBeTrue()
        ->and($this->envFile->get('DB_PASSWORD'))->toBe('')
        ->and(config('database.connections.mysql.host'))->toBe('127.0.0.1')
        ->and(config('database.connections.mysql.port'))->toBe('3306')
        ->and(config('database.connections.mysql.database'))->toBe($database)
        ->and(config('database.connections.mysql.username'))->toBe('root')
        ->and(config('database.connections.mysql.password'))->toBe('');
});

test('the sail server changes the host and username defaults', function (): void {
    Prompt::fake([Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);
    file_put_contents($this->dir.'/.env', "DB_CONNECTION=mysql\n");

    $context = new ServeContext(new ServeOptions, $this->sailServer);

    ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($this->envFile->get('DB_HOST'))->toBe('mysql')
        ->and($this->envFile->get('DB_USERNAME'))->toBe('sail')
        ->and(config('database.connections.mysql.host'))->toBe('mysql')
        ->and(config('database.connections.mysql.username'))->toBe('sail');
});

test('only the absent password key is prompted; present values survive', function (): void {
    Prompt::fake([Key::ENTER]);

    file_put_contents($this->dir.'/.env', implode("\n", [
        'DB_CONNECTION=mysql',
        'DB_HOST=db.internal',
        'DB_PORT=3307',
        'DB_DATABASE=igne',
        'DB_USERNAME=deploy',
    ])."\n");

    ($this->step)()->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    expect($this->envFile->get('DB_HOST'))->toBe('db.internal')
        ->and($this->envFile->get('DB_PORT'))->toBe('3307')
        ->and($this->envFile->get('DB_USERNAME'))->toBe('deploy')
        ->and($this->envFile->has('DB_PASSWORD'))->toBeTrue()
        ->and($this->envFile->get('DB_PASSWORD'))->toBe('');
});

test('the pgsql driver from .env beats the config default and sets its port', function (): void {
    Prompt::fake([Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER]);
    file_put_contents($this->dir.'/.env', "DB_CONNECTION=pgsql\n");
    config()->set('database.default', 'testing');

    ($this->step)()->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    expect($this->envFile->get('DB_PORT'))->toBe('5432')
        ->and(config('database.connections.pgsql.port'))->toBe('5432')
        ->and(config('database.connections.pgsql.host'))->toBe('127.0.0.1');
});
