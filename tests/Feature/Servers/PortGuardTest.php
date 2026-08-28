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
        envRestore: envRestorePoint($workDir),
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

/**
 * Hold a real port the guard has to notice.
 *
 * Deliberately not an OS-assigned ephemeral port: those land in 49152-65535,
 * and one near the top leaves fewer free ports above it than the search
 * window looks at — so the guard would sometimes find nowhere to move to and
 * the test would fail for a reason it is not about. A quiet mid-range port
 * has room above it, like the real FORWARD_* and APP_PORT values do.
 */
function heldPort(object $test): int
{
    for ($port = 20_000; $port < 30_000; $port++) {
        $socket = @stream_socket_server("tcp://0.0.0.0:{$port}");

        if ($socket !== false) {
            $test->sockets[] = $socket;

            return $port;
        }
    }

    throw new RuntimeException('no free port in 20000-30000 to stage a conflict with');
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
    Prompt::assertStrippedOutputContains('Ports updated in .env.');
});

test('a port with a URL to match moves both, in one write', function (): void {
    Prompt::fake([Key::ENTER]);
    ProcessFaker::fake();
    file_put_contents($this->envPath, "APP_URL=http://localhost\nDB_HOST=mysql\n");
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(
        port: $taken,
        purpose: 'laravel.test',
        envKey: 'APP_PORT',
        urlKey: 'APP_URL',
    ));

    portGuard($this->workDir)->guard(guardContext($server));

    $envFile = new EnvFile($this->envPath, $this->workDir.'/.env.example');
    $moved = (int) $envFile->get('APP_PORT');

    // A port the application advertises is only moved once the address it
    // advertises moves with it.
    expect($moved)->toBeGreaterThan($taken)
        ->and($envFile->get('APP_URL'))->toBe("http://localhost:{$moved}");
});

test('an existing port and path in the URL are replaced and kept', function (): void {
    Prompt::fake([Key::ENTER]);
    ProcessFaker::fake();
    file_put_contents($this->envPath, "APP_URL=https://dashboard.test:80/app\n");
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(
        port: $taken,
        purpose: 'laravel.test',
        envKey: 'APP_PORT',
        urlKey: 'APP_URL',
    ));

    portGuard($this->workDir)->guard(guardContext($server));

    $envFile = new EnvFile($this->envPath, $this->workDir.'/.env.example');

    expect($envFile->get('APP_URL'))->toBe('https://dashboard.test:'.$envFile->get('APP_PORT').'/app');
});

test('a URL with nothing worth keeping is replaced outright', function (): void {
    Prompt::fake([Key::ENTER]);
    ProcessFaker::fake();
    file_put_contents($this->envPath, "APP_URL=\n");
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(
        port: $taken,
        purpose: 'laravel.test',
        envKey: 'APP_PORT',
        urlKey: 'APP_URL',
    ));

    portGuard($this->workDir)->guard(guardContext($server));

    $envFile = new EnvFile($this->envPath, $this->workDir.'/.env.example');

    expect($envFile->get('APP_URL'))->toBe('http://localhost:'.$envFile->get('APP_PORT'));
});

test('the replacement is looked for where the port itself says', function (): void {
    Prompt::fake([Key::ENTER]);
    ProcessFaker::fake();
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(port: $taken, purpose: 'mysql', envKey: 'FORWARD_DB_PORT'));

    portGuard($this->workDir)->guard(guardContext($server));

    $envFile = new EnvFile($this->envPath, $this->workDir.'/.env.example');

    expect((int) $envFile->get('FORWARD_DB_PORT'))
        ->toBeGreaterThanOrEqual((new ReservedPort(port: $taken, purpose: 'mysql'))->searchFrom());
});

test('a moved forward is kept — the machine still owns that port next boot', function (): void {
    Prompt::fake([Key::ENTER]);
    ProcessFaker::fake();
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(port: $taken, purpose: 'mysql', envKey: 'FORWARD_DB_PORT'));

    portGuard($this->workDir)->guard(guardContext($server));

    $envFile = new EnvFile($this->envPath, $this->workDir.'/.env.example');
    $moved = $envFile->get('FORWARD_DB_PORT');

    envRestorePoint($this->workDir)->restore();

    // Undoing it would only ask the same question again on the next boot.
    expect($envFile->get('FORWARD_DB_PORT'))->toBe($moved);
});

test('the moved ports are recorded so the teardown can put them back', function (): void {
    Prompt::fake([Key::ENTER]);
    ProcessFaker::fake();
    file_put_contents($this->envPath, "APP_URL=http://localhost\n");
    $taken = heldPort($this);
    $server = portServer(new ReservedPort(
        port: $taken,
        purpose: 'laravel.test',
        envKey: 'APP_PORT',
        urlKey: 'APP_URL',
    ));

    portGuard($this->workDir)->guard(guardContext($server));
    envRestorePoint($this->workDir)->restore();

    $env = (string) file_get_contents($this->envPath);

    // APP_URL describes how one server served the project; the next run may
    // serve it another way.
    expect($env)->toContain('APP_URL=http://localhost')
        ->and($env)->not->toContain('APP_PORT');
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
