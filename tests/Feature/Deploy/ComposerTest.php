<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Deploy\Composer;
use Igne\LaravelBootUp\Exceptions\DeployException;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\LockfileConflictDetector;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-composer-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    // A vendor tree that is stale relative to the lock, so install() runs by
    // default. Tests that need "up to date" adjust the mtimes below.
    mkdir($this->dir.'/vendor/composer', 0755, true);
    touch($this->dir.'/vendor/autoload.php', time() - 100);
    touch($this->dir.'/vendor/composer/installed.json', time() - 100);
    touch($this->dir.'/composer.json', time() - 100);
    touch($this->dir.'/composer.lock', time());
    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

// Process::fake() must run before this resolves the Factory.
function deployComposer(string $dir): Composer
{
    return new Composer(
        new ProcessRunner(
            processes: app(Factory::class),
            ledger: new ProcessLedger($dir.'/processes.json'),
            terminal: new NullTerminalLauncher,
            poller: new Poller,
            logDirectory: $dir.'/logs',
            runtimeDirectory: $dir.'/runtime',
        ),
        new LockfileConflictDetector,
        $dir,
    );
}

/** Mark vendor/ as freshly installed (installed.json newer than the lock). */
function markComposerUpToDate(string $dir): void
{
    $now = time();
    touch($dir.'/composer.lock', $now - 100);
    touch($dir.'/composer.json', $now - 100);
    touch($dir.'/vendor/composer/installed.json', $now);
    touch($dir.'/vendor/autoload.php', $now);
}

function composerCommandOf(object $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : $process->command;
}

test('install runs composer install host-side', function (): void {
    Process::fake(['*' => Process::result()]);

    deployComposer($this->dir)->install();

    Process::assertRan(fn ($process): bool => composerCommandOf($process) === 'composer install');
    Process::assertDidntRun(fn ($process): bool => str_starts_with(composerCommandOf($process), 'composer update'));
});

test('install is skipped when vendor is already in sync with the lockfile', function (): void {
    Process::fake(['*' => Process::result()]);
    markComposerUpToDate($this->dir);

    deployComposer($this->dir)->install();

    Process::assertDidntRun(fn ($process): bool => str_starts_with(composerCommandOf($process), 'composer install'));
    Prompt::assertStrippedOutputContains('already up to date');
});

test('install runs when the lockfile is newer than the last install', function (): void {
    Process::fake(['*' => Process::result()]);
    markComposerUpToDate($this->dir);
    touch($this->dir.'/composer.lock', time() + 10); // lock changed after install

    deployComposer($this->dir)->install();

    Process::assertRan(fn ($process): bool => composerCommandOf($process) === 'composer install');
});

test('install runs when vendor is missing', function (): void {
    Process::fake(['*' => Process::result()]);
    markComposerUpToDate($this->dir);
    exec('rm -rf '.escapeshellarg($this->dir.'/vendor'));

    deployComposer($this->dir)->install();

    Process::assertRan(fn ($process): bool => composerCommandOf($process) === 'composer install');
});

test('the update flag runs even when vendor is in sync', function (): void {
    Process::fake(['*' => Process::result()]);
    markComposerUpToDate($this->dir);

    deployComposer($this->dir)->install(update: true);

    Process::assertRan(fn ($process): bool => composerCommandOf($process) === 'composer update');
});

test('the update flag switches to composer update', function (): void {
    Process::fake(['*' => Process::result()]);

    deployComposer($this->dir)->install(update: true);

    Process::assertRan(fn ($process): bool => composerCommandOf($process) === 'composer update');
    Process::assertDidntRun(fn ($process): bool => composerCommandOf($process) === 'composer install');
});

test('a lockfile conflict regenerates the lock and retries the install once', function (): void {
    // Fake patterns match Symfony's escaped command line, hence the wildcards.
    Process::fake([
        '*--lock*' => Process::result(),
        '*install*' => Process::sequence()
            ->push(Process::result(exitCode: 1, errorOutput: 'Your lock file is not up to date with the latest changes in composer.json.'))
            ->push(Process::result()),
    ]);

    deployComposer($this->dir)->install();

    Process::assertRan(fn ($process): bool => composerCommandOf($process) === 'composer update --lock');
    Process::assertRanTimes(fn ($process): bool => composerCommandOf($process) === 'composer install', 2);
    Prompt::assertStrippedOutputContains('composer.lock is out of sync');
});

test('a non-conflict install failure throws without retrying', function (): void {
    Process::fake([
        '*install*' => Process::result(exitCode: 1, errorOutput: 'Fatal error: something unrelated'),
        '*' => Process::result(),
    ]);

    expect(fn () => deployComposer($this->dir)->install())
        ->toThrow(DeployException::class, 'Composer failed');

    Process::assertDidntRun(fn ($process): bool => composerCommandOf($process) === 'composer update --lock');
});

test('a failure during the retry surfaces as a DeployException', function (): void {
    Process::fake([
        '*--lock*' => Process::result(),
        '*install*' => Process::sequence()
            ->push(Process::result(exitCode: 1, errorOutput: 'Your lock file is not up to date with the latest changes in composer.json.'))
            ->push(Process::result(exitCode: 1, errorOutput: 'still broken')),
    ]);

    expect(fn () => deployComposer($this->dir)->install())
        ->toThrow(DeployException::class);

    Process::assertRanTimes(fn ($process): bool => composerCommandOf($process) === 'composer install', 2);
});

test('an update failure never triggers lock regeneration', function (): void {
    Process::fake([
        '*--lock*' => Process::result(),
        '*update*' => Process::result(exitCode: 1, errorOutput: 'Your lock file is not up to date with the latest changes in composer.json.'),
    ]);

    expect(fn () => deployComposer($this->dir)->install(update: true))
        ->toThrow(DeployException::class);

    Process::assertDidntRun(fn ($process): bool => composerCommandOf($process) === 'composer update --lock');
});
