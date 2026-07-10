<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Deploy\DeployConfig;
use Igne\LaravelBootstrap\Deploy\Steps\CacheFrameworkFiles;
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
    $this->dir = sys_get_temp_dir().'/bootstrap-cache-files-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

// Process::fake() must run before this resolves the Factory.
function cacheFrameworkFilesStep(string $dir, bool $enabled): CacheFrameworkFiles
{
    return new CacheFrameworkFiles(
        new DeployConfig(cacheFrameworkFiles: $enabled, finalize: ['storage:link']),
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

function cacheFrameworkFilesCommandOf(object $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : $process->command;
}

test('skips with a note when caching is disabled', function (): void {
    Process::fake();

    $context = new ServeContext(new ServeOptions);

    $result = cacheFrameworkFilesStep($this->dir, enabled: false)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('Framework file caching is disabled');
});

test('caches config, routes and views host-side when enabled', function (): void {
    Process::fake(['*' => Process::result()]);

    $context = new ServeContext(new ServeOptions);

    $result = cacheFrameworkFilesStep($this->dir, enabled: true)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);

    foreach (['config:cache', 'route:cache', 'view:cache'] as $command) {
        Process::assertRan(fn ($process): bool => cacheFrameworkFilesCommandOf($process) === "php artisan {$command}");
    }

    Process::assertRanTimes(fn ($process): bool => true, 3);
});
