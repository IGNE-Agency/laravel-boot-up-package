<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ToolsConfig;
use Igne\LaravelBootUp\Contracts\InstallsTool;
use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\ToolStatus;
use Igne\LaravelBootUp\Exceptions\ToolException;
use Igne\LaravelBootUp\Tools\ToolManager;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

function toolManagerDouble(
    bool $installed,
    ?string $version = null,
    bool $updatesAutomatically = false,
    ?string $versionAfterUpdate = null,
): InstallsTool {
    return new class($installed, $version, $updatesAutomatically, $versionAfterUpdate) implements InstallsTool
    {
        public int $installs = 0;

        public int $updates = 0;

        public int $versionReads = 0;

        public function __construct(
            private readonly bool $installed,
            private ?string $version,
            private readonly bool $auto,
            private readonly ?string $versionAfterUpdate = null,
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

            if ($this->versionAfterUpdate !== null) {
                $this->version = $this->versionAfterUpdate;
            }
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

    $outcome = toolManagerWith()->ensure($tool, VersionConstraint::wildcard());

    expect($tool->installs)->toBe(1)
        ->and($tool->updates)->toBe(0)
        ->and($outcome->status)->toBe(ToolStatus::Installed);
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

    $outcome = toolManagerWith()->ensure($tool, VersionConstraint::wildcard());

    expect($tool->installs)->toBe(0)
        ->and($tool->updates)->toBe(0)
        ->and($tool->versionReads)->toBe(0)
        ->and($outcome->status)->toBe(ToolStatus::Satisfied)
        ->and($outcome->label)->toBe('Double');
    Prompt::assertStrippedOutputDoesntContain('Double is installed.');
});

test('warns and continues when the installed version cannot be read', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: null);

    $outcome = toolManagerWith()->ensure($tool, VersionConstraint::of('^8.3'));

    expect($tool->updates)->toBe(0)
        ->and($outcome->status)->toBe(ToolStatus::Unverified);
    Prompt::assertStrippedOutputContains('Could not determine the installed Double version');
});

test('a satisfied constraint requires no action', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: '8.3.5');

    $outcome = toolManagerWith()->ensure($tool, VersionConstraint::of('^8.3'));

    expect($tool->installs)->toBe(0)
        ->and($tool->updates)->toBe(0)
        ->and($outcome->status)->toBe(ToolStatus::Satisfied)
        ->and($outcome->version)->toBe('8.3.5');
    Prompt::assertStrippedOutputDoesntContain('satisfies');
});

test('updates an outdated tool and reports the version it reached', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: '8.2.0', versionAfterUpdate: '8.3.7');

    $outcome = toolManagerWith()->ensure($tool, VersionConstraint::of('^8.3'));

    expect($tool->updates)->toBe(1)
        ->and($tool->installs)->toBe(0)
        ->and($outcome->status)->toBe(ToolStatus::Updated)
        ->and($outcome->version)->toBe('8.3.7');
    Prompt::assertStrippedOutputContains('Updating');
    Prompt::assertStrippedOutputContains('Double updated to 8.3.7.');
});

test('an update that cannot reach the constraint warns instead of pretending success', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: '8.2.0', versionAfterUpdate: '8.2.1');

    $outcome = toolManagerWith()->ensure($tool, VersionConstraint::of('^8.3'));

    expect($tool->updates)->toBe(1)
        ->and($outcome->status)->toBe(ToolStatus::Unverified);
    Prompt::assertStrippedOutputContains("Double is 8.2.1 after updating, which still does not satisfy '^8.3'.");
});

test('skips updating tools that update themselves', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: '8.2.0', updatesAutomatically: true);

    $outcome = toolManagerWith()->ensure($tool, VersionConstraint::of('^8.3'));

    expect($tool->updates)->toBe(0)
        ->and($outcome->status)->toBe(ToolStatus::SkippedSelfUpdating);
    Prompt::assertStrippedOutputContains('updates itself');
});

test('warns without updating when auto-update is disabled', function (): void {
    Prompt::fake();
    $tool = toolManagerDouble(installed: true, version: '8.2.0');

    $outcome = toolManagerWith(autoUpdate: false)->ensure($tool, VersionConstraint::of('^8.3'));

    expect($tool->updates)->toBe(0)
        ->and($outcome->status)->toBe(ToolStatus::Unverified);
    Prompt::assertStrippedOutputContains('boot-up.tools.auto_update');
});
