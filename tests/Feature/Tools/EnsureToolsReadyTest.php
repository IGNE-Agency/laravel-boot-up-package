<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ToolsConfig;
use Igne\LaravelBootUp\Contracts\RequiresTools;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Tests\Feature\Tools\Fixtures\AlphaToolSpy;
use Igne\LaravelBootUp\Tests\Feature\Tools\Fixtures\BunToolSpy;
use Igne\LaravelBootUp\Tests\Feature\Tools\Fixtures\DockerToolSpy;
use Igne\LaravelBootUp\Tests\Feature\Tools\Fixtures\EnsureToolsReadySpy;
use Igne\LaravelBootUp\Tools\Steps\EnsureToolsReady;
use Laravel\Prompts\Prompt;

function ensureToolsServer(array $tools, ?CommandRewrites $rewrites = null): Server
{
    return new class($tools, $rewrites) implements RequiresTools, RewritesCommands, Server
    {
        public function __construct(
            private readonly array $tools,
            private readonly ?CommandRewrites $rewrites = null,
        ) {}

        public function key(): string
        {
            return 'fake';
        }

        public function label(): string
        {
            return 'Fake Server';
        }

        public function requiredTools(): array
        {
            return $this->tools;
        }

        public function commandRewrites(): CommandRewrites
        {
            return $this->rewrites ?? CommandRewrites::none();
        }

        public function isRunning(): bool
        {
            return false;
        }

        public function start(ServeContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
}

function bindToolsConfig(array $required, array $installers): void
{
    config()->set('boot-up.tools.required', $required);
    config()->set('boot-up.tools.installers', $installers);

    app()->instance(ToolsConfig::class, ToolsConfig::fromRepository(config()));
}

beforeEach(function (): void {
    EnsureToolsReadySpy::$ensured = [];
    Prompt::fake();
});

test('ensures every configured tool and then the server tools', function (): void {
    bindToolsConfig(
        required: ['alpha' => '^1.0'],
        installers: ['alpha' => AlphaToolSpy::class, 'docker' => DockerToolSpy::class],
    );

    $context = new ServeContext(new ServeOptions, ensureToolsServer([Tool::DOCKER]));

    $result = app(EnsureToolsReady::class)->handle($context, fn ($passed) => $passed);

    expect(EnsureToolsReadySpy::$ensured)->toBe(['alpha', 'docker'])
        ->and($result)->toBe($context);
    Prompt::assertStrippedOutputContains('Dependencies ready');
    Prompt::assertStrippedOutputContains('• Alpha 1.0.0');
    Prompt::assertStrippedOutputContains('• Docker');
    Prompt::assertStrippedOutputContains('All required dependencies are installed.');
});

test('quiet successes no longer print their own lines', function (): void {
    bindToolsConfig(
        required: ['alpha' => '^1.0'],
        installers: ['alpha' => AlphaToolSpy::class],
    );

    app(EnsureToolsReady::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Prompt::assertStrippedOutputDoesntContain('satisfies');
    Prompt::assertStrippedOutputDoesntContain('is installed.');
});

test('no summary is printed when nothing was ensured', function (): void {
    bindToolsConfig(required: [], installers: ['bun' => BunToolSpy::class]);
    bindPackageJson(exists: false);

    app(EnsureToolsReady::class)->handle(new ServeContext(new ServeOptions, ensureToolsServer([])), fn ($passed) => $passed);

    Prompt::assertStrippedOutputDoesntContain('Dependencies ready');
});

test('server tools already covered by the required map are not ensured twice', function (): void {
    bindToolsConfig(
        required: ['docker' => '*'],
        installers: ['docker' => DockerToolSpy::class],
    );

    $context = new ServeContext(new ServeOptions, ensureToolsServer([Tool::DOCKER]));

    app(EnsureToolsReady::class)->handle($context, fn ($passed) => $passed);

    expect(EnsureToolsReadySpy::$ensured)->toBe(['docker']);
});

test('a null server (app:deploy) only ensures the required map', function (): void {
    bindToolsConfig(
        required: ['alpha' => '*'],
        installers: ['alpha' => AlphaToolSpy::class],
    );

    $context = new ServeContext(new ServeOptions);

    app(EnsureToolsReady::class)->handle($context, fn ($passed) => $passed);

    expect(EnsureToolsReadySpy::$ensured)->toBe(['alpha']);
});

function bindPackageJson(bool $exists): void
{
    $dir = sys_get_temp_dir().'/boot-up-tools-pkg-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    if ($exists) {
        file_put_contents($dir.'/package.json', '{}');
    }

    app()->instance(PackageJson::class, new PackageJson($dir.'/package.json'));
}

test('the selected frontend package manager is ensured when a package.json exists', function (): void {
    bindToolsConfig(required: [], installers: ['bun' => BunToolSpy::class]);
    bindPackageJson(exists: true);

    app(EnsureToolsReady::class)->handle(new ServeContext(new ServeOptions, ensureToolsServer([])), fn ($passed) => $passed);

    expect(EnsureToolsReadySpy::$ensured)->toBe(['bun']);
});

test('the package manager is not ensured twice when the required map already covers it', function (): void {
    bindToolsConfig(required: ['bun' => '*'], installers: ['bun' => BunToolSpy::class]);
    bindPackageJson(exists: true);

    app(EnsureToolsReady::class)->handle(new ServeContext(new ServeOptions, ensureToolsServer([])), fn ($passed) => $passed);

    expect(EnsureToolsReadySpy::$ensured)->toBe(['bun']);
});

test('the package manager is skipped without a package.json', function (): void {
    bindToolsConfig(required: [], installers: ['bun' => BunToolSpy::class]);
    bindPackageJson(exists: false);

    app(EnsureToolsReady::class)->handle(new ServeContext(new ServeOptions, ensureToolsServer([])), fn ($passed) => $passed);

    expect(EnsureToolsReadySpy::$ensured)->toBe([]);
});

test('the package manager is skipped with --without-assets', function (): void {
    bindToolsConfig(required: [], installers: ['bun' => BunToolSpy::class]);
    bindPackageJson(exists: true);

    $context = new ServeContext(new ServeOptions(withAssets: false), ensureToolsServer([]));

    app(EnsureToolsReady::class)->handle($context, fn ($passed) => $passed);

    expect(EnsureToolsReadySpy::$ensured)->toBe([]);
});

test('the package manager is skipped when the server wraps its binary', function (): void {
    bindToolsConfig(required: [], installers: ['bun' => BunToolSpy::class]);
    bindPackageJson(exists: true);

    $sailLike = ensureToolsServer([], new CommandRewrites(
        prefixes: ['php', 'composer', 'bun'],
        prefix: './vendor/bin/sail',
    ));

    app(EnsureToolsReady::class)->handle(new ServeContext(new ServeOptions, $sailLike), fn ($passed) => $passed);

    expect(EnsureToolsReadySpy::$ensured)->toBe([]);
});
