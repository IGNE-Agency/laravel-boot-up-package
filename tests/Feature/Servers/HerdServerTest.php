<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\Terminal\NullTerminal;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\ServeOptions;
use Igne\LaravelBootstrap\Servers\Herd\HerdServer;
use Igne\LaravelBootstrap\Servers\Herd\HerdServices;
use Igne\LaravelBootstrap\Support\Poller;
use Igne\LaravelBootstrap\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Igne\LaravelBootstrap\Tools\Tool;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/bootstrap-herd-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function herdServer(string $workDir, ?string $projectPath = null): HerdServer
{
    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($workDir.'/processes.json'),
        terminal: new NullTerminal,
        poller: new Poller,
        logDirectory: $workDir.'/logs',
        runtimeDirectory: $workDir.'/runtime',
    );

    return new HerdServer($runner, new HerdServices($runner), $projectPath);
}

test('start links the project and secures it', function (): void {
    ProcessFaker::fake();

    herdServer($this->workDir)->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('herd link');
    ProcessFaker::assertRan('herd secure');
    Prompt::assertStrippedOutputContains('Project linked to Herd.');
    Prompt::assertStrippedOutputContains('HTTPS certificate configured.');
});

test('isRunning is true when nginx is up', function (): void {
    ProcessFaker::fake(['pgrep -x nginx' => Process::result()]);

    expect(herdServer($this->workDir)->isRunning())->toBeTrue();
    ProcessFaker::assertDidntRun('pgrep -x php-fpm');
});

test('isRunning is true when only php-fpm is up', function (): void {
    ProcessFaker::fake([
        'pgrep -x nginx' => Process::result(exitCode: 1),
        'pgrep -x php-fpm' => Process::result(),
    ]);

    expect(herdServer($this->workDir)->isRunning())->toBeTrue();
});

test('isRunning is false when no herd service is up', function (): void {
    ProcessFaker::fake(['pgrep*' => Process::result(exitCode: 1)]);

    expect(herdServer($this->workDir)->isRunning())->toBeFalse();
});

test('stop runs herd stop', function (): void {
    ProcessFaker::fake();

    herdServer($this->workDir)->stop();

    ProcessFaker::assertRan('herd stop');
});

test('url derives from the project directory name, not app.url', function (): void {
    ProcessFaker::fake();
    config()->set('app.url', 'http://wrong.example');

    expect(herdServer($this->workDir, '/Users/dev/projects/my-app')->url())
        ->toBe('https://my-app.test');
});

test('identity, tools and rewrites', function (): void {
    ProcessFaker::fake();
    $server = herdServer($this->workDir);
    $rewrites = $server->commandRewrites();

    expect($server->key())->toBe('herd')
        ->and($server->label())->toBe('Laravel Herd')
        ->and($server->requiredTools())->toBe([Tool::HERD])
        ->and($rewrites->replaces)->toBe([])
        ->and($rewrites->prefixes)->toBe(['php', 'composer', 'tinker'])
        ->and($rewrites->prefix)->toBe('herd');
});
