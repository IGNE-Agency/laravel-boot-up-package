<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\ServeOptions;
use Igne\LaravelBootstrap\Servers\CommandRewrites;
use Igne\LaravelBootstrap\Servers\Server;
use Igne\LaravelBootstrap\Tests\Feature\Tools\Fixtures\AlphaToolSpy;
use Igne\LaravelBootstrap\Tests\Feature\Tools\Fixtures\DockerToolSpy;
use Igne\LaravelBootstrap\Tests\Feature\Tools\Fixtures\EnsureToolsReadySpy;
use Igne\LaravelBootstrap\Tools\Steps\EnsureToolsReady;
use Igne\LaravelBootstrap\Tools\Tool;
use Igne\LaravelBootstrap\Tools\ToolsConfig;
use Laravel\Prompts\Prompt;

function ensureToolsServer(array $tools): Server
{
    return new class($tools) implements Server
    {
        public function __construct(private readonly array $tools) {}

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
            return CommandRewrites::none();
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
    config()->set('bootstrap.tools.required', $required);
    config()->set('bootstrap.tools.installers', $installers);

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
    Prompt::assertStrippedOutputContains("Alpha 1.0.0 satisfies '^1.0'.");
    Prompt::assertStrippedOutputContains('Docker is installed.');
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
