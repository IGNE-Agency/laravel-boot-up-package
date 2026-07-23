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
        // delay 0 keeps the retry loop instant under test; attempts stay at 10.
        new HerdServices($runner, healthDelayMs: 0),
        new HerdSites($workDir.'/Sites'),
        new ServersConfig(herdSite: $site),
        $projectPath,
    );
}

test('start links the project under the configured site name and secures it', function (): void {
    ProcessFaker::fake(['curl*' => Process::result('200')]);

    herdServer($this->workDir, $this->projectDir, site: 'dashboard')->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('herd link dashboard');
    ProcessFaker::assertRan('herd secure dashboard');
    Prompt::assertStrippedOutputContains('Project linked to Laravel Herd as https://dashboard.test.');
    Prompt::assertStrippedOutputContains('HTTPS certificate configured.');
});

test('start prompts for the site name, defaulting to the project folder', function (): void {
    ProcessFaker::fake(['curl*' => Process::result('200')]);
    Prompt::fake([Key::ENTER]);

    herdServer($this->workDir, $this->projectDir)->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('herd link my-app');
    ProcessFaker::assertRan('herd secure my-app');
});

test('an already-linked project skips linking and does not re-secure', function (): void {
    ProcessFaker::fake(['curl*' => Process::result('200')]);
    symlink($this->projectDir, $this->sitesDir.'/custom-name');

    herdServer($this->workDir, $this->projectDir)->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertDidntRun('herd link*');
    // Re-securing on every serve reloads Nginx and caused false "not answering".
    ProcessFaker::assertDidntRun('herd secure*');
    Prompt::assertStrippedOutputContains('Project already linked to Laravel Herd as https://custom-name.test.');
});

test('a stale link to a moved project is replaced automatically', function (): void {
    ProcessFaker::fake(['curl*' => Process::result('200')]);
    symlink($this->workDir.'/moved-away', $this->sitesDir.'/my-app');

    herdServer($this->workDir, $this->projectDir, site: 'my-app')->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('herd unlink my-app');
    ProcessFaker::assertRan('herd link my-app');
    Prompt::assertStrippedOutputContains('no longer exists');
});

test('a name owned by another live project is only replaced after confirmation', function (): void {
    ProcessFaker::fake(['curl*' => Process::result('200')]);
    mkdir($this->workDir.'/other-project');
    symlink($this->workDir.'/other-project', $this->sitesDir.'/my-app');
    Prompt::fake(['y', Key::ENTER]);

    herdServer($this->workDir, $this->projectDir, site: 'my-app')->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('herd unlink my-app');
    ProcessFaker::assertRan('herd link my-app');
});

test('declining the takeover prompts for a different site name', function (): void {
    ProcessFaker::fake(['curl*' => Process::result('200')]);
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

test('start reports the server ready once Nginx answers, without restarting a healthy Herd', function (): void {
    // pgrep succeeds (Herd already running) and the site answers on the first probe.
    ProcessFaker::fake(['curl*' => Process::result('200')]);

    herdServer($this->workDir, $this->projectDir, site: 'my-app')->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('curl* https://my-app.test');
    ProcessFaker::assertDidntRun('herd start');
    ProcessFaker::assertDidntRun('herd restart');
    Prompt::assertStrippedOutputContains('Laravel Herd is serving https://my-app.test.');
});

test('start boots Herd when none of its processes are running', function (): void {
    ProcessFaker::fake([
        'pgrep*' => Process::result(exitCode: 1), // Herd is down
        'curl*' => Process::result('200'),         // ...but answers once booted
    ]);

    herdServer($this->workDir, $this->projectDir, site: 'my-app')->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('herd start');
    Prompt::assertStrippedOutputContains('Laravel Herd is serving https://my-app.test.');
});

test('a running Herd that is briefly slow is never restarted', function (): void {
    $probe = 0;
    ProcessFaker::fake([
        'pgrep*' => Process::result(),  // Herd services are up throughout
        'curl*' => function () use (&$probe) {
            $probe++;

            // Refused on the first couple of probes, then answers — a running
            // Herd finishing a reload, not a stuck one.
            return $probe >= 3 ? Process::result('200') : Process::result('000', exitCode: 7);
        },
    ]);

    herdServer($this->workDir, $this->projectDir, site: 'my-app')->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertDidntRun('herd restart');
    ProcessFaker::assertDidntRun('herd start');
    Prompt::assertStrippedOutputContains('Laravel Herd is serving https://my-app.test.');
});

test('start restarts Herd once at the midpoint only when its services are down', function (): void {
    $probe = 0;
    ProcessFaker::fake([
        'pgrep*' => Process::result(exitCode: 1), // Herd services down throughout
        'curl*' => function () use (&$probe) {
            $probe++;

            // Unreachable until the mid-way restart kicks in (attempt 5 of 10),
            // then answers on the next probe.
            return $probe > 5 ? Process::result('200') : Process::result('000', exitCode: 7);
        },
    ]);

    herdServer($this->workDir, $this->projectDir, site: 'my-app')->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('herd start');            // booted because down
    ProcessFaker::assertRanTimes('herd restart', 1);  // then one midpoint restart
    Prompt::assertStrippedOutputContains('Laravel Herd is serving https://my-app.test.');
});

test('start fails with actionable guidance after exhausting the health attempts', function (): void {
    // Herd services are up but Nginx never answers: connection refused on every probe.
    ProcessFaker::fake([
        'pgrep*' => Process::result(),
        'curl*' => Process::result('000', exitCode: 7),
    ]);

    expect(fn () => herdServer($this->workDir, $this->projectDir, site: 'my-app')->start(new ServeContext(new ServeOptions)))
        ->toThrow(ServerException::class, 'did not become reachable');

    // A running-but-unreachable Herd is never restarted — guidance is surfaced instead.
    ProcessFaker::assertDidntRun('herd restart');
});

test('isRunning is true when Herd nginx is up', function (): void {
    ProcessFaker::fake(['pgrep -f Herd[^ ]*nginx' => Process::result()]);

    expect(herdServer($this->workDir)->isRunning())->toBeTrue();
    ProcessFaker::assertDidntRun('pgrep -f Herd[^ ]*php-fpm');
});

test('isRunning is true when only Herd php-fpm is up', function (): void {
    ProcessFaker::fake([
        'pgrep -f Herd[^ ]*nginx' => Process::result(exitCode: 1),
        'pgrep -f Herd[^ ]*php-fpm' => Process::result(),
    ]);

    expect(herdServer($this->workDir)->isRunning())->toBeTrue();
});

test('the health probe is scoped to Herd paths, never bare service names', function (): void {
    ProcessFaker::fake(['pgrep*' => Process::result(exitCode: 1)]);

    herdServer($this->workDir)->isRunning();

    ProcessFaker::assertDidntRun('pgrep -x nginx');
    ProcessFaker::assertDidntRun('pgrep -x php-fpm');
    ProcessFaker::assertRan('pgrep -f Herd*nginx');
    ProcessFaker::assertRan('pgrep -f Herd*php-fpm');
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
