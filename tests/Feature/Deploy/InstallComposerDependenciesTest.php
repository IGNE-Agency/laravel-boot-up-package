<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Deploy\Composer;
use Igne\LaravelBootstrap\Deploy\Steps\InstallComposerDependencies;
use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\Terminal\NullTerminal;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\ServeOptions;
use Igne\LaravelBootstrap\Support\LockfileConflictDetector;
use Igne\LaravelBootstrap\Support\Poller;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bootstrap-install-composer-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

// Process::fake() must run before this resolves the Factory.
function installComposerStep(string $dir): InstallComposerDependencies
{
    return new InstallComposerDependencies(new Composer(
        new ProcessRunner(
            processes: app(Factory::class),
            ledger: new ProcessLedger($dir.'/processes.json'),
            terminal: new NullTerminal,
            poller: new Poller,
            logDirectory: $dir.'/logs',
            runtimeDirectory: $dir.'/runtime',
        ),
        new LockfileConflictDetector,
    ));
}

function installComposerCommandOf(object $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : $process->command;
}

test('installs composer dependencies and continues the pipeline', function (): void {
    Process::fake(['*' => Process::result()]);

    $context = new ServeContext(new ServeOptions);

    $result = installComposerStep($this->dir)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertRan(fn ($process): bool => installComposerCommandOf($process) === 'composer install');
});

test('the --update serve option switches the step to composer update', function (): void {
    Process::fake(['*' => Process::result()]);

    $context = new ServeContext(new ServeOptions(update: true));

    installComposerStep($this->dir)->handle($context, fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => installComposerCommandOf($process) === 'composer update');
    Process::assertDidntRun(fn ($process): bool => installComposerCommandOf($process) === 'composer install');
});
