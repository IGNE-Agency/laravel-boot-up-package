<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Igne\LaravelBootUp\Tools\Installers\PackageManagerInstaller;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

function packageManagerInstaller(Tool $tool): PackageManagerInstaller
{
    return app()->make(PackageManagerInstaller::class, ['tool' => $tool]);
}

test('brew-based managers install and upgrade via Homebrew', function (Tool $tool): void {
    ProcessFaker::fake(['command -v brew*' => Process::result('/opt/homebrew/bin/brew')]);

    packageManagerInstaller($tool)->install(VersionConstraint::wildcard());
    ProcessFaker::assertRan("brew install {$tool->value}");

    packageManagerInstaller($tool)->update(VersionConstraint::wildcard());
    ProcessFaker::assertRan("brew upgrade {$tool->value}");
})->with([
    'bun' => [Tool::Bun],
    'pnpm' => [Tool::Pnpm],
]);

test('yarn is enabled through Corepack on install and update, never from brew', function (string $action): void {
    ProcessFaker::fake(['brew list --formula yarn' => Process::result(exitCode: 1)]);

    packageManagerInstaller(Tool::Yarn)->{$action}(VersionConstraint::wildcard());

    ProcessFaker::assertRan('corepack enable yarn');
    ProcessFaker::assertDidntRun('npm install -g corepack');
    ProcessFaker::assertDidntRun('brew install*');
    ProcessFaker::assertDidntRun('brew upgrade*');
    ProcessFaker::assertDidntRun('brew uninstall*');
})->with(['install', 'update']);

test('corepack is installed through npm where Node no longer bundles it', function (): void {
    ProcessFaker::fake([
        "sh -c command -v 'corepack'" => Process::result(exitCode: 1),
        'brew list --formula yarn' => Process::result(exitCode: 1),
    ]);

    packageManagerInstaller(Tool::Yarn)->install(VersionConstraint::wildcard());

    ProcessFaker::assertRan('npm install -g corepack');
    ProcessFaker::assertRan('corepack enable yarn');
});

test("Homebrew's Yarn 1 is removed, announced, so it cannot shadow Corepack's shim", function (): void {
    Prompt::fake();
    ProcessFaker::fake();

    packageManagerInstaller(Tool::Yarn)->update(VersionConstraint::of('>=2'));

    ProcessFaker::assertRan('brew uninstall yarn');
    ProcessFaker::assertRan('corepack enable yarn');
    Prompt::assertStrippedOutputContains("Removing Homebrew's Yarn 1");
});

test('without Homebrew there is no Yarn formula to remove', function (): void {
    ProcessFaker::fake(["sh -c command -v 'brew'" => Process::result(exitCode: 1)]);

    packageManagerInstaller(Tool::Yarn)->install(VersionConstraint::wildcard());

    ProcessFaker::assertDidntRun('brew list*');
    ProcessFaker::assertDidntRun('brew uninstall*');
    ProcessFaker::assertRan('corepack enable yarn');
});

test('npm installs and updates itself through npm', function (): void {
    ProcessFaker::fake();

    packageManagerInstaller(Tool::Npm)->install(VersionConstraint::wildcard());
    packageManagerInstaller(Tool::Npm)->update(VersionConstraint::wildcard());

    ProcessFaker::assertRanTimes('npm install -g npm', 2);
    ProcessFaker::assertDidntRun('brew*');
});
