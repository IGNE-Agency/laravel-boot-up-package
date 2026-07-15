<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\Terminal\NullTerminal;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\ServeOptions;
use Igne\LaravelBootUp\Servers\Herd\HerdServer;
use Igne\LaravelBootUp\Servers\Herd\HerdServices;
use Igne\LaravelBootUp\Servers\Herd\HerdSites;
use Igne\LaravelBootUp\Servers\ServerException;
use Igne\LaravelBootUp\Servers\ServersConfig;
use Igne\LaravelBootUp\Support\Poller;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Igne\LaravelBootUp\Tools\Tool;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/boot-up-herd-'.bin2hex(random_bytes(4));
    $this->projectDir = $this->workDir.'/my-app';
    $this->sitesDir = $this->workDir.'/Sites';
    mkdir($this->projectDir, 0755, true);
    mkdir($this->sitesDir, 0755, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function herdServer(string $workDir, ?string $projectPath = null, ?string $site = null): HerdServer
{
    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($workDir.'/processes.json'),
        terminal: new NullTerminal,
        poller: new Poller,
        logDirectory: $workDir.'/logs',
        runtimeDirectory: $workDir.'/runtime',
    );

    return new HerdServer(
        $runner,
        new HerdServices($runner),
        new HerdSites($workDir.'/Sites'),
        new ServersConfig(herdSite: $site),
        $projectPath,
    );
}

test('start links the project under the configured site name and secures it', function (): void {
    ProcessFaker::fake();

    herdServer($this->workDir, $this->projectDir, site: 'dashboard')->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('herd link dashboard');
    ProcessFaker::assertRan('herd secure dashboard');
    Prompt::assertStrippedOutputContains('Project linked to Herd as https://dashboard.test.');
    Prompt::assertStrippedOutputContains('HTTPS certificate configured.');
});

test('start prompts for the site name, defaulting to the project folder', function (): void {
    ProcessFaker::fake();
    Prompt::fake([Key::ENTER]);

    herdServer($this->workDir, $this->projectDir)->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('herd link my-app');
    ProcessFaker::assertRan('herd secure my-app');
});

test('an already-linked project skips linking and keeps its site name', function (): void {
    ProcessFaker::fake();
    symlink($this->projectDir, $this->sitesDir.'/custom-name');

    herdServer($this->workDir, $this->projectDir)->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertDidntRun('herd link*');
    ProcessFaker::assertRan('herd secure custom-name');
    Prompt::assertStrippedOutputContains('Project already linked to Herd as https://custom-name.test.');
});

test('a stale link to a moved project is replaced automatically', function (): void {
    ProcessFaker::fake();
    symlink($this->workDir.'/moved-away', $this->sitesDir.'/my-app');

    herdServer($this->workDir, $this->projectDir, site: 'my-app')->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('herd unlink my-app');
    ProcessFaker::assertRan('herd link my-app');
    Prompt::assertStrippedOutputContains('no longer exists');
});

test('a name owned by another live project is only replaced after confirmation', function (): void {
    ProcessFaker::fake();
    mkdir($this->workDir.'/other-project');
    symlink($this->workDir.'/other-project', $this->sitesDir.'/my-app');
    Prompt::fake(['y', Key::ENTER]);

    herdServer($this->workDir, $this->projectDir, site: 'my-app')->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('herd unlink my-app');
    ProcessFaker::assertRan('herd link my-app');
});

test('declining the takeover prompts for a different site name', function (): void {
    ProcessFaker::fake();
    mkdir($this->workDir.'/other-project');
    symlink($this->workDir.'/other-project', $this->sitesDir.'/taken');
    Prompt::fake([Key::ENTER, Key::ENTER]); // decline replace, accept the folder-name default

    herdServer($this->workDir, $this->projectDir, site: 'taken')->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertDidntRun('herd unlink*');
    ProcessFaker::assertRan('herd link my-app');
});

test('a failing herd command aborts the start instead of pretending success', function (): void {
    ProcessFaker::fake(['herd link*' => Process::result(exitCode: 1, errorOutput: 'no herd here')]);

    expect(fn () => herdServer($this->workDir, $this->projectDir, site: 'my-app')->start(new ServeContext(new ServeOptions)))
        ->toThrow(ServerException::class, 'no herd here');
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

test('url uses the registry site name when the project is linked', function (): void {
    ProcessFaker::fake();
    symlink($this->projectDir, $this->sitesDir.'/custom-name');

    expect(herdServer($this->workDir, $this->projectDir)->url())->toBe('https://custom-name.test');
});

test('url falls back to the project directory name, not app.url', function (): void {
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
