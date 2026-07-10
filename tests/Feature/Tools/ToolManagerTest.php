<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Tools\InstallsTool;
use Igne\LaravelBootstrap\Tools\ToolException;
use Igne\LaravelBootstrap\Tools\ToolManager;
use Igne\LaravelBootstrap\Tools\ToolsConfig;
use Igne\LaravelBootstrap\Tools\VersionConstraint;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

function toolManagerDouble(bool $installed, ?string $version = null, bool $updatesAutomatically = false): InstallsTool
{
    return new class($installed, $version, $updatesAutomatically) implements InstallsTool
    {
        public int $installs = 0;

        public int $updates = 0;

        public int $versionReads = 0;

        public function __construct(
            private readonly bool $installed,
            private readonly ?string $version,
            private readonly bool $auto,
        ) {}

        public function id(): string
        {
            return 'double';
        }

        public function label(): string
        {
            return 'Double';
        }

        public function isInstalled(): bool
        {
            return $this->installed;
        }

        public function installedVersion(): ?string
        {
            $this->versionReads++;

            return $this->version;
        }

        public function install(VersionConstraint $constraint): void
        {
            $this->installs++;
        }

        public function update(VersionConstraint $constraint): void
        {
            $this->updates++;
        }

        public function updatesAutomatically(): bool
        {
            return $this->auto;
        }
    };
}

function toolManagerWith(bool $autoInstall = true, bool $autoUpdate = true): ToolManager
{
    return new ToolManager(new ToolsConfig(
        autoInstall: $autoInstall,
        autoUpdate: $autoUpdate,
        required: [],
        installers: [],
    ));
}

test('installs a missing tool when auto-install is enabled', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: false);

    toolManagerWith()->ensure($tool, VersionConstraint::wildcard());

    expect($tool->installs)->toBe(1)
        ->and($tool->updates)->toBe(0);
    Prompt::assertStrippedOutputContains('Double not found. Installing...');
});

test('prompts and installs when auto-install is off and the user agrees', function (): void {
    Prompt::fake([Key::ENTER]);
    $tool = toolManagerDouble(installed: false);

    toolManagerWith(autoInstall: false)->ensure($tool, VersionConstraint::wildcard());

    expect($tool->installs)->toBe(1);
});

test('throws when auto-install is off and the user declines', function (): void {
    Prompt::fake(['n', Key::ENTER]);
    $tool = toolManagerDouble(installed: false);

    expect(fn () => toolManagerWith(autoInstall: false)->ensure($tool, VersionConstraint::wildcard()))
        ->toThrow(ToolException::class, 'Double is not installed.');

    expect($tool->installs)->toBe(0);
});

test('presence alone satisfies a wildcard constraint', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: '9.9.9');

    toolManagerWith()->ensure($tool, VersionConstraint::wildcard());

    expect($tool->installs)->toBe(0)
        ->and($tool->updates)->toBe(0)
        ->and($tool->versionReads)->toBe(0);
    Prompt::assertStrippedOutputContains('Double is installed.');
});

test('warns and continues when the installed version cannot be read', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: null);

    toolManagerWith()->ensure($tool, VersionConstraint::of('^8.3'));

    expect($tool->updates)->toBe(0);
    Prompt::assertStrippedOutputContains('Could not determine the installed Double version');
});

test('a satisfied constraint requires no action', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: '8.3.5');

    toolManagerWith()->ensure($tool, VersionConstraint::of('^8.3'));

    expect($tool->installs)->toBe(0)
        ->and($tool->updates)->toBe(0);
    Prompt::assertStrippedOutputContains("Double 8.3.5 satisfies '^8.3'.");
});

test('updates an outdated tool when auto-update is enabled', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: '8.2.0');

    toolManagerWith()->ensure($tool, VersionConstraint::of('^8.3'));

    expect($tool->updates)->toBe(1)
        ->and($tool->installs)->toBe(0);
    Prompt::assertStrippedOutputContains('Updating');
});

test('skips updating tools that update themselves', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: '8.2.0', updatesAutomatically: true);

    toolManagerWith()->ensure($tool, VersionConstraint::of('^8.3'));

    expect($tool->updates)->toBe(0);
    Prompt::assertStrippedOutputContains('updates itself');
});

test('warns without updating when auto-update is disabled', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: '8.2.0');

    toolManagerWith(autoUpdate: false)->ensure($tool, VersionConstraint::of('^8.3'));

    expect($tool->updates)->toBe(0);
    Prompt::assertStrippedOutputContains('bootstrap.tools.auto_update');
});
