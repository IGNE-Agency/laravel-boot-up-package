<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Deploy\Steps\FinalizeApplication;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-finalize-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

// Process::fake() must run before this resolves the Factory.
function finalizeApplicationStep(string $dir, array $finalize): FinalizeApplication
{
    return new FinalizeApplication(
        new DeployConfig(cacheFrameworkFiles: false, finalize: $finalize),
        new ProcessRunner(
            processes: app(Factory::class),
            ledger: new ProcessLedger($dir.'/processes.json'),
            terminal: new NullTerminalLauncher,
            poller: new Poller,
            logDirectory: $dir.'/logs',
            runtimeDirectory: $dir.'/runtime',
        ),
    );
}

function finalizeApplicationCommandOf(object $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : $process->command;
}

test('runs each configured finalize command as php artisan host-side', function (): void {
    Process::fake(['*' => Process::result()]);
    // A link target that does not exist, so storage:link is not skipped.
    config()->set('filesystems.links', [$this->dir.'/public/storage' => $this->dir.'/storage/app/public']);

    $context = new ServeContext(new ServeOptions);

    $result = finalizeApplicationStep($this->dir, ['storage:link', 'horizon:publish --force'])
        ->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertRan(fn ($process): bool => finalizeApplicationCommandOf($process) === 'php artisan storage:link');
    Process::assertRan(fn ($process): bool => finalizeApplicationCommandOf($process) === 'php artisan horizon:publish --force');
    Process::assertRanTimes(fn ($process): bool => true, 2);
});

test('storage:link is skipped with a note when every configured link already exists', function (): void {
    Process::fake(['*' => Process::result()]);
    $link = $this->dir.'/public-storage';
    symlink($this->dir, $link);
    config()->set('filesystems.links', [$link => $this->dir.'/target']);

    finalizeApplicationStep($this->dir, ['storage:link'])
        ->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertDidntRun(fn ($process): bool => finalizeApplicationCommandOf($process) === 'php artisan storage:link');
    Prompt::assertStrippedOutputContains('Storage already linked.');
});

test('storage:link still runs when a configured link is missing', function (): void {
    Process::fake(['*' => Process::result()]);
    config()->set('filesystems.links', [$this->dir.'/missing' => $this->dir.'/target']);

    finalizeApplicationStep($this->dir, ['storage:link'])
        ->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => finalizeApplicationCommandOf($process) === 'php artisan storage:link');
});

test('a forced storage:link is never skipped', function (): void {
    Process::fake(['*' => Process::result()]);
    $link = $this->dir.'/public-storage';
    symlink($this->dir, $link);
    config()->set('filesystems.links', [$link => $this->dir.'/target']);

    finalizeApplicationStep($this->dir, ['storage:link --force'])
        ->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => finalizeApplicationCommandOf($process) === 'php artisan storage:link --force');
});

test('an empty finalize list is a no-op', function (): void {
    Process::fake();

    $context = new ServeContext(new ServeOptions);

    $result = finalizeApplicationStep($this->dir, [])->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNothingRan();
});
