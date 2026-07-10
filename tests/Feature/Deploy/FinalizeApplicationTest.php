<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Deploy\DeployConfig;
use Igne\LaravelBootstrap\Deploy\Steps\FinalizeApplication;
use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\Terminal\NullTerminal;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\ServeOptions;
use Igne\LaravelBootstrap\Support\Poller;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bootstrap-finalize-'.bin2hex(random_bytes(4));
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
            terminal: new NullTerminal,
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

    $context = new ServeContext(new ServeOptions);

    $result = finalizeApplicationStep($this->dir, ['storage:link', 'horizon:publish --force'])
        ->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertRan(fn ($process): bool => finalizeApplicationCommandOf($process) === 'php artisan storage:link');
    Process::assertRan(fn ($process): bool => finalizeApplicationCommandOf($process) === 'php artisan horizon:publish --force');
    Process::assertRanTimes(fn ($process): bool => true, 2);
});

test('an empty finalize list is a no-op', function (): void {
    Process::fake();

    $context = new ServeContext(new ServeOptions);

    $result = finalizeApplicationStep($this->dir, [])->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNothingRan();
});
