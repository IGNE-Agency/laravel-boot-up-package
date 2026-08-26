<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Contracts\ReservesPorts;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Data\ReservedPort;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\PortGuard;
use Igne\LaravelBootUp\Services\PortProbe;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/boot-up-port-guard-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
    $this->envPath = $this->workDir.'/.env';
    file_put_contents($this->envPath, "APP_NAME=Boot\nDB_HOST=mysql\n");
    $this->sockets = [];
});

afterEach(function (): void {
    foreach ($this->sockets as $socket) {
        fclose($socket);
    }

    exec('rm -rf '.escapeshellarg($this->workDir));
});

function portGuard(string $workDir, bool $checkPorts = true, bool $autoAccept = false): PortGuard
{
    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($workDir.'/processes.json'),
        logDirectory: $workDir.'/logs',
    );

    return new PortGuard(
        probe: new PortProbe($runner),
        envFile: new EnvFile($workDir.'/.env', $workDir.'/.env.example'),
        serverConfig: new DevServerConfig(checkPorts: $checkPorts),
        setupConfig: new SetupConfig(autoAccept: $autoAccept),
    );
}

/**
 * A driver that reserves whatever the test says, and records whether it was
 * ever asked.
 */
function portServer(ReservedPort ...$ports): Server
{
    return new class($ports) implements ReservesPorts, Server
    {
        public int $asked = 0;

        /**
         * @param  list<ReservedPort>  $ports
         */
        public function __construct(private readonly array $ports) {}

        public function reservedPorts(): array
        {
            $this->asked++;

            return $this->ports;
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
            return false;
        }

        public function start(BootContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
}

function heldPort(object $test): int
{
    $socket = stream_socket_server('tcp://0.0.0.0:0', $errno, $error);

    expect($socket)->not->toBeFalse("could not bind a test port: {$error}");

    $test->sockets[] = $socket;
    $name = (string) stream_socket_get_name($socket, false);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

function guardContext(Server $server, bool $autoAccept = false): BootContext
{
    return new BootContext(new BootOptions(autoAccept: $autoAccept), $server);
}

test('free ports pass without a word', function (): void {
    ProcessFaker::fake();
    $server = portServer(new ReservedPort(port: 65_432, purpose: 'mysql', envKey: 'FORWARD_DB_PORT'));

    portGuard($this->workDir)->guard(guardContext($server));

    expect($server->asked)->toBe(1)
        ->and((string) file_get_contents($this->envPath))->not->toContain('FORWARD_DB_PORT');
});

test('an unmovable port stops the boot, naming the port and its holder', function (): void {
    ProcessFaker::fake([
        'sh -c *' => Process::result(output: '/usr/sbin/lsof'),
        'lsof *' => Process::result(output: "p7964\ncnginx-arm\n"),
    ]);
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(
        port: $taken,
        purpose: 'laravel.test',
        fix: 'set APP_PORT in your .env (and APP_URL to match)',
    ));

    expect(fn () => portGuard($this->workDir)->guard(guardContext($server)))
        ->toThrow(ServerException::class, "{$taken} (laravel.test) — held by nginx-arm (PID 7964)");

    expect(fn () => portGuard($this->workDir)->guard(guardContext($server)))
        ->toThrow(ServerException::class, 'set APP_PORT in your .env');
});

test('one unmovable port settles it for the movable ones too', function (): void {
    ProcessFaker::fake();
    $fixed = heldPort($this);
    $forward = heldPort($this);
    $server = portServer(
        new ReservedPort(port: $forward, purpose: 'mysql', envKey: 'FORWARD_DB_PORT'),
        new ReservedPort(port: $fixed, purpose: 'laravel.test', fix: 'set APP_PORT in your .env'),
    );

    // Both are named: a boot that reports only one sends the user round the
    // loop again for the other.
    expect(fn () => portGuard($this->workDir)->guard(guardContext($server)))
        ->toThrow(ServerException::class, '2 of the host ports it needs are already in use');

    expect((string) file_get_contents($this->envPath))->not->toContain('FORWARD_DB_PORT');
});

test('a movable port is moved in the .env once confirmed', function (): void {
    Prompt::fake([Key::ENTER]);
    ProcessFaker::fake();
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(port: $taken, purpose: 'mysql', envKey: 'FORWARD_DB_PORT'));

    portGuard($this->workDir)->guard(guardContext($server));

    $env = (string) file_get_contents($this->envPath);

    expect($env)->toMatch('/^FORWARD_DB_PORT=\d+$/m')
        // The application still reaches MySQL over the container network, so
        // the connection itself is left alone.
        ->and($env)->toContain('DB_HOST=mysql')
        ->and((int) explode('=', (string) preg_split('/\R/', $env)[2])[1])->toBeGreaterThan($taken);
    Prompt::assertStrippedOutputContains('Double Server needs host ports that are already in use');
    Prompt::assertStrippedOutputContains('Port forwards updated in .env.');
});

test('declining the move stops the boot instead', function (): void {
    Prompt::fake([Key::LEFT, Key::ENTER]);
    ProcessFaker::fake();
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(port: $taken, purpose: 'mysql', envKey: 'FORWARD_DB_PORT'));

    expect(fn () => portGuard($this->workDir)->guard(guardContext($server)))
        ->toThrow(ServerException::class, 'Free them or move them');

    expect((string) file_get_contents($this->envPath))->not->toContain('FORWARD_DB_PORT');
});

test('two moved ports never land on each other', function (): void {
    Prompt::fake([Key::ENTER]);
    ProcessFaker::fake();

    // Two adjacent taken ports: naively each search starts one past its own
    // port and both settle on the same free one.
    $first = heldPort($this);
    $second = heldPort($this);
    $server = portServer(
        new ReservedPort(port: $first, purpose: 'mysql', envKey: 'FORWARD_DB_PORT'),
        new ReservedPort(port: $second, purpose: 'redis', envKey: 'FORWARD_REDIS_PORT'),
    );

    portGuard($this->workDir)->guard(guardContext($server));

    $envFile = new EnvFile($this->envPath, $this->workDir.'/.env.example');

    expect($envFile->get('FORWARD_DB_PORT'))->not->toBe($envFile->get('FORWARD_REDIS_PORT'));
});

test('--yes moves the ports without asking', function (): void {
    ProcessFaker::fake();
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(port: $taken, purpose: 'mysql', envKey: 'FORWARD_DB_PORT'));

    portGuard($this->workDir)->guard(guardContext($server, autoAccept: true));

    expect((string) file_get_contents($this->envPath))->toMatch('/^FORWARD_DB_PORT=\d+$/m');
    Prompt::assertStrippedOutputContains('Moving them to free ports in your .env.');
});

test('auto-accept in the config moves them too', function (): void {
    ProcessFaker::fake();
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(port: $taken, purpose: 'mysql', envKey: 'FORWARD_DB_PORT'));

    portGuard($this->workDir, autoAccept: true)->guard(guardContext($server));

    expect((string) file_get_contents($this->envPath))->toMatch('/^FORWARD_DB_PORT=\d+$/m');
});

test('the check can be turned off', function (): void {
    ProcessFaker::fake();
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(port: $taken, purpose: 'mysql', envKey: 'FORWARD_DB_PORT'));

    portGuard($this->workDir, checkPorts: false)->guard(guardContext($server));

    expect($server->asked)->toBe(0)
        ->and((string) file_get_contents($this->envPath))->not->toContain('FORWARD_DB_PORT');
});

test('a driver that publishes nothing is never probed', function (): void {
    ProcessFaker::fake();

    // Herd serves through its own nginx and never implements the contract.
    $plain = new class implements Server
    {
        public function key(): string
        {
            return 'plain';
        }

        public function label(): string
        {
            return 'Plain';
        }

        public function isRunning(): bool
        {
            return false;
        }

        public function start(BootContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };

    portGuard($this->workDir)->guard(guardContext($plain));
})->throwsNoExceptions();

test('a server the boot never picked is nothing to guard', function (): void {
    ProcessFaker::fake();

    portGuard($this->workDir)->guard(new BootContext(new BootOptions));
})->throwsNoExceptions();
