<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Contracts\ReservesPorts;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Data\ReservedPort;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\PortGuard;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Igne\LaravelBootUp\Services\PortProbe;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    $this->workDir = sys_get_temp_dir().'/boot-up-start-server-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
    $this->store = new ActiveServerStore($this->workDir.'/active-server.json');
    $this->sockets = [];
});

afterEach(function (): void {
    foreach ($this->sockets as $socket) {
        fclose($socket);
    }

    exec('rm -rf '.escapeshellarg($this->workDir));
});

function startServerStep(object $test): StartServer
{
    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($test->workDir.'/processes.json'),
        logDirectory: $test->workDir.'/logs',
    );

    return new StartServer($test->store, new PortGuard(
        probe: new PortProbe($runner),
        envFile: new EnvFile($test->workDir.'/.env', $test->workDir.'/.env.example'),
        envRestore: envRestorePoint($test->workDir),
        serverConfig: new DevServerConfig,
        setupConfig: new SetupConfig,
    ));
}

/**
 * A driver double that captures the persisted state as observed from
 * INSIDE start(), proving the write-ahead ordering.
 */
function startServerDouble(ActiveServerStore $store, bool $running, ?ReservedPort $port = null): Server
{
    return new class($store, $running, $port) implements ReservesPorts, Server
    {
        public ?ActiveServerRecord $observedAtStart = null;

        public int $starts = 0;

        public int $asked = 0;

        public function __construct(
            private readonly ActiveServerStore $store,
            private readonly bool $running,
            private readonly ?ReservedPort $port = null,
        ) {}

        public function reservedPorts(): array
        {
            $this->asked++;

            return $this->port === null ? [] : [$this->port];
        }

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

    $result = startServerStep($this)->handle($context, fn (BootContext $passed): BootContext => $passed);

    expect($server->starts)->toBe(1)
        ->and($server->observedAtStart)->not->toBeNull()
        ->and($server->observedAtStart->key)->toBe('double')
        ->and($server->observedAtStart->startedByUs)->toBeTrue()
        ->and($server->observedAtStart->setupPid)->toBe((int) getmypid())
        ->and($result)->toBe($context);
    Prompt::assertStrippedOutputContains('Double Server is running.');
});

test('records startedByUs=false when the server was already running', function (): void {
    $server = startServerDouble($this->store, running: true);
    $context = new BootContext(new BootOptions, $server);

    startServerStep($this)->handle($context, fn (BootContext $passed): BootContext => $passed);

    expect($server->starts)->toBe(1)
        ->and($server->observedAtStart->startedByUs)->toBeFalse();
});

test('a null server (app:deploy) passes through without touching the store', function (): void {
    $context = new BootContext(new BootOptions);

    $result = startServerStep($this)->handle($context, fn (BootContext $passed): BootContext => $passed);

    expect($result)->toBe($context)
        ->and($this->store->current())->toBeNull()
        ->and(is_file($this->workDir.'/active-server.json'))->toBeFalse();
});

test('a taken port stops the boot before anything is persisted', function (): void {
    $socket = stream_socket_server('tcp://0.0.0.0:0');
    $this->sockets[] = $socket;
    $name = (string) stream_socket_get_name($socket, false);
    $taken = (int) substr($name, (int) strrpos($name, ':') + 1);

    $server = startServerDouble($this->store, running: false, port: new ReservedPort(
        port: $taken,
        purpose: 'laravel.test',
        fix: 'set APP_PORT in your .env',
    ));
    $context = new BootContext(new BootOptions, $server);

    expect(fn () => startServerStep($this)->handle($context, fn (BootContext $passed): BootContext => $passed))
        ->toThrow(ServerException::class, 'Double Server cannot start');

    // Nothing was started, so there must be nothing for app:down to find.
    expect($server->starts)->toBe(0)
        ->and($this->store->current())->toBeNull()
        ->and(is_file($this->workDir.'/active-server.json'))->toBeFalse();
});

test('a running server is never asked about its own ports', function (): void {
    // It is holding them itself, and would be reported as clashing with
    // itself.
    $server = startServerDouble($this->store, running: true, port: new ReservedPort(port: 80, purpose: 'laravel.test'));
    $context = new BootContext(new BootOptions, $server);

    startServerStep($this)->handle($context, fn (BootContext $passed): BootContext => $passed);

    expect($server->asked)->toBe(0)
        ->and($server->starts)->toBe(1);
});
