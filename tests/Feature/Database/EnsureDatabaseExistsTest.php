<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Database\DatabaseCreator;
use Igne\LaravelBootUp\Database\Steps\EnsureDatabaseExists;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\DefaultServerCapabilities;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-db-exists-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    Prompt::fake();

    $this->sqlitePath = $this->dir.'/database.sqlite';

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->sqlitePath,
    ]);

    $this->step = fn (?DatabaseConfig $config = null): EnsureDatabaseExists => new EnsureDatabaseExists(
        $config ?? new DatabaseConfig,
        new DatabaseCreator,
        config(),
    );

    $this->sailServer = new class implements Server
    {
        use DefaultServerCapabilities;

        public function providesDatabase(): bool
        {
            return true;
        }

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
            return [];
        }

        public function commandRewrites(): CommandRewrites
        {
            return CommandRewrites::none();
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

test('skips with a note when creation is disabled in configuration', function (): void {
    $config = new DatabaseConfig(create: false);
    $context = new ServeContext(new ServeOptions);

    $result = ($this->step)($config)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context)
        ->and(is_file($this->sqlitePath))->toBeFalse();
    Prompt::assertStrippedOutputContains('disabled');
});

test('skips with a note under sail — the container provisions the database', function (): void {
    $context = new ServeContext(new ServeOptions, $this->sailServer);

    $result = ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context)
        ->and(is_file($this->sqlitePath))->toBeFalse();
    Prompt::assertStrippedOutputContains('Sail');
});

test('touches the sqlite database file when it is missing', function (): void {
    $context = new ServeContext(new ServeOptions);

    $result = ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context)
        ->and(is_file($this->sqlitePath))->toBeTrue();
    Prompt::assertStrippedOutputContains('created');
});

test('notes when the database already exists', function (): void {
    touch($this->sqlitePath);

    ($this->step)()->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Prompt::assertStrippedOutputContains('already exists');
});
