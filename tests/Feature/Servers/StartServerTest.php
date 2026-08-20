<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/boot-up-start-server-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
    $this->store = new ActiveServerStore($this->workDir.'/active-server.json');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

/**
 * A driver double that captures the persisted state as observed from
 * INSIDE start(), proving the write-ahead ordering.
 */
function startServerDouble(ActiveServerStore $store, bool $running): Server
{
    return new class($store, $running) implements Server
    {
        public ?ActiveServerRecord $observedAtStart = null;

        public int $starts = 0;

        public function __construct(
            private readonly ActiveServerStore $store,
            private readonly bool $running,
        ) {}

        public function key(): string
        {
            return 'double';
        }

        public function label(): string
        {
            return 'Double Server';
        }

        public function isRunning(): bool
        {
            return $this->running;
        }

        public function start(BootContext $context): void
        {
            $this->starts++;
            $this->observedAtStart = $this->store->current();
        }

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
}

test('persists the active-server record before the driver starts', function (): void {
    $server = startServerDouble($this->store, running: false);
    $context = new BootContext(new BootOptions, $server);

    $result = (new StartServer($this->store))->handle($context, fn (BootContext $passed): BootContext => $passed);

    expect($server->starts)->toBe(1)
        ->and($server->observedAtStart)->not->toBeNull()
        ->and($server->observedAtStart->key)->toBe('double')
        ->and($server->observedAtStart->startedByUs)->toBeTrue()
        ->and($server->observedAtStart->servePid)->toBe((int) getmypid())
        ->and($context->serverWasAlreadyRunning)->toBeFalse()
        ->and($result)->toBe($context);
    Prompt::assertStrippedOutputContains('Double Server is running.');
});

test('records startedByUs=false when the server was already running', function (): void {
    $server = startServerDouble($this->store, running: true);
    $context = new BootContext(new BootOptions, $server);

    (new StartServer($this->store))->handle($context, fn (BootContext $passed): BootContext => $passed);

    expect($server->starts)->toBe(1)
        ->and($server->observedAtStart->startedByUs)->toBeFalse()
        ->and($context->serverWasAlreadyRunning)->toBeTrue();
});

test('a null server (app:deploy) passes through without touching the store', function (): void {
    $context = new BootContext(new BootOptions);

    $result = (new StartServer($this->store))->handle($context, fn (BootContext $passed): BootContext => $passed);

    expect($result)->toBe($context)
        ->and($this->store->current())->toBeNull()
        ->and(is_file($this->workDir.'/active-server.json'))->toBeFalse();
});
