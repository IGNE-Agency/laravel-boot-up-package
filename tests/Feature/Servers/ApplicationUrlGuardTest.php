<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Contracts\AdvertisesUrl;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\EnvRestorePoint;
use Igne\LaravelBootUp\Servers\ApplicationUrlGuard;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/boot-up-app-url-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
    $this->envPath = $this->workDir.'/.env';
    $this->envFile = new EnvFile($this->envPath, $this->workDir.'/.env.example');
    $this->envRestore = new EnvRestorePoint($this->envFile, $this->workDir.'/env-restore.json');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function urlGuard(object $test, bool $reconcile = true): ApplicationUrlGuard
{
    return new ApplicationUrlGuard(
        envFile: $test->envFile,
        envRestore: $test->envRestore,
        config: new DevServerConfig(reconcileAppUrl: $reconcile),
        laravelConfig: app('config'),
    );
}

/**
 * A driver that answers on one URL and recognises only that one, which is
 * all the guard asks of a server.
 */
function urlServer(string $served = 'http://localhost:8080'): Server
{
    return new class($served) implements AdvertisesUrl, Server
    {
        public function __construct(private readonly string $served) {}

        public function servedUrl(): string
        {
            return $this->served;
        }

        public function serves(string $url): bool
        {
            return $url === $this->served;
        }

        public function key(): string
        {
            return 'double';
        }

        public function label(): string
        {
            return 'The Double';
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function start(BootContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return $this->served;
        }
    };
}

function urlContext(?Server $server): BootContext
{
    return new BootContext(options: new BootOptions, server: $server);
}

test('a url that does not reach the server is corrected for the session', function (): void {
    file_put_contents($this->envPath, "APP_NAME=Boot\nAPP_URL=https://project.test\n");

    urlGuard($this)->reconcile(urlContext(urlServer()));

    expect($this->envFile->get('APP_URL'))->toBe('http://localhost:8080')
        ->and(config('app.url'))->toBe('http://localhost:8080');

    // Session-scoped, exactly like a moved port: the project's own URL comes
    // back when the server it was wrong for is gone.
    $this->envRestore->restore();

    expect($this->envFile->get('APP_URL'))->toBe('https://project.test');
});

test('a url the port guard already moved still restores to the original', function (): void {
    file_put_contents($this->envPath, "APP_URL=https://project.test\n");

    // What a port move writes first: same hostname, the port it had to take.
    $this->envRestore->around(fn () => $this->envFile->set('APP_URL', 'https://project.test:8080'));

    urlGuard($this)->reconcile(urlContext(urlServer()));

    expect($this->envFile->get('APP_URL'))->toBe('http://localhost:8080');

    // Two writes to one key, one original: the value from before the boot.
    $this->envRestore->restore();

    expect($this->envFile->get('APP_URL'))->toBe('https://project.test');
});

test('a url that already reaches the server is left alone', function (): void {
    file_put_contents($this->envPath, "APP_URL=http://localhost:8080\n");

    urlGuard($this)->reconcile(urlContext(urlServer()));

    expect($this->envFile->get('APP_URL'))->toBe('http://localhost:8080');

    $this->envRestore->restore();

    expect($this->envFile->get('APP_URL'))->toBe('http://localhost:8080');
});

test('a missing APP_URL is written, and removed again at teardown', function (): void {
    file_put_contents($this->envPath, "APP_NAME=Boot\n");

    urlGuard($this)->reconcile(urlContext(urlServer()));

    expect($this->envFile->get('APP_URL'))->toBe('http://localhost:8080');

    $this->envRestore->restore();

    expect($this->envFile->has('APP_URL'))->toBeFalse();
});

test('a driver that does not advertise a url keeps the project value', function (): void {
    file_put_contents($this->envPath, "APP_URL=https://project.test\n");

    $server = new class implements Server
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
            return true;
        }

        public function start(BootContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'https://project.test';
        }
    };

    urlGuard($this)->reconcile(urlContext($server));

    expect($this->envFile->get('APP_URL'))->toBe('https://project.test');
});

test('turning the reconciliation off leaves .env untouched', function (): void {
    file_put_contents($this->envPath, "APP_URL=https://project.test\n");

    urlGuard($this, reconcile: false)->reconcile(urlContext(urlServer()));

    expect($this->envFile->get('APP_URL'))->toBe('https://project.test');
});

test('no server means nothing to reconcile against', function (): void {
    file_put_contents($this->envPath, "APP_URL=https://project.test\n");

    urlGuard($this)->reconcile(urlContext(null));

    expect($this->envFile->get('APP_URL'))->toBe('https://project.test');
});
