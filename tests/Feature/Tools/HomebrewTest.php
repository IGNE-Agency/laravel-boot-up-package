<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Tools\Installers\Homebrew;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

const HOMEBREW_INSTALL_SCRIPT = 'curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh | bash';

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-homebrew-test-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function makeHomebrew(string $workDir): Homebrew
{
    return new Homebrew(new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($workDir.'/processes.json'),
        logDirectory: $workDir.'/logs',
    ));
}

function commandString(object $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : $process->command;
}

test('ensureInstalled installs brew via the official install script when missing', function (): void {
    Process::fake([
        '*command -v*brew*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);
    Prompt::fake();

    makeHomebrew($this->workDir)->ensureInstalled();

    Process::assertRan(function ($process): bool {
        $command = commandString($process);

        return str_starts_with($command, 'bash -c')
            && str_contains($command, HOMEBREW_INSTALL_SCRIPT);
    });
    Prompt::assertStrippedOutputContains('Homebrew not found. Installing...');
});

test('ensureInstalled is a no-op when brew is already available', function (): void {
    Process::fake(['*' => Process::result()]);

    makeHomebrew($this->workDir)->ensureInstalled();

    Process::assertDidntRun(fn ($process): bool => str_contains(commandString($process), 'install.sh'));
});

test('install runs brew install for a formula', function (): void {
    Process::fake(['*' => Process::result()]);

    makeHomebrew($this->workDir)->install('node');

    Process::assertRan(fn ($process): bool => commandString($process) === 'brew install node');
});

test('install renders casks with the --cask flag', function (): void {
    Process::fake(['*' => Process::result()]);

    makeHomebrew($this->workDir)->install('docker', cask: true);

    Process::assertRan(fn ($process): bool => commandString($process) === 'brew install --cask docker');
});

test('upgrade runs brew upgrade for a formula', function (): void {
    Process::fake(['*' => Process::result()]);

    makeHomebrew($this->workDir)->upgrade('node');

    Process::assertRan(fn ($process): bool => commandString($process) === 'brew upgrade node');
});
