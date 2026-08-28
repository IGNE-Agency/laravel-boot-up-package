<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\Browser;
use Igne\LaravelBootUp\Enums\OperatingSystem;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\Platform;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;

function browserOn(OperatingSystem $family): Browser
{
    return new Browser(
        new ProcessRunner(
            processes: app(Factory::class),
            ledger: new ProcessLedger(sys_get_temp_dir().'/boot-up-browser-'.bin2hex(random_bytes(4)).'.json'),
            logDirectory: sys_get_temp_dir(),
        ),
        new Platform($family),
    );
}

test('macOS opens with open', function (): void {
    Process::fake();

    browserOn(OperatingSystem::Darwin)->open('http://app.test');

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', $process->command), 'open http://app.test'));
});

test('Windows hands the URL to explorer', function (): void {
    Process::fake();

    browserOn(OperatingSystem::Windows)->open('http://app.test');

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', $process->command), 'explorer.exe'));
});

test('plain Linux uses xdg-open', function (): void {
    Process::fake();
    $wsl = $_SERVER['WSL_DISTRO_NAME'] ?? null;
    unset($_SERVER['WSL_DISTRO_NAME']);

    try {
        browserOn(OperatingSystem::Linux)->open('http://app.test');

        $expected = is_file('/proc/sys/fs/binfmt_misc/WSLInterop') ? 'wslview' : 'xdg-open';
        Process::assertRan(fn ($process): bool => str_contains(implode(' ', $process->command), $expected));
    } finally {
        $wsl === null ? null : $_SERVER['WSL_DISTRO_NAME'] = $wsl;
    }
});

test('WSL hands the URL out to the host', function (): void {
    Process::fake();
    $_SERVER['WSL_DISTRO_NAME'] = 'Ubuntu';

    try {
        browserOn(OperatingSystem::Linux)->open('http://app.test');

        Process::assertRan(fn ($process): bool => str_contains(implode(' ', $process->command), 'wslview'));
    } finally {
        unset($_SERVER['WSL_DISTRO_NAME']);
    }
});
