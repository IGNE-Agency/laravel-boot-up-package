<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Igne\LaravelBootUp\Tools\Installers\PackageManagerInstaller;

function packageManagerInstaller(Tool $tool): PackageManagerInstaller
{
    return app()->make(PackageManagerInstaller::class, ['tool' => $tool]);
}

test('brew-based managers install and upgrade via Homebrew', function (Tool $tool): void {
    ProcessFaker::fake(['command -v brew*' => Illuminate\Support\Facades\Process::result('/opt/homebrew/bin/brew')]);

    packageManagerInstaller($tool)->install(VersionConstraint::wildcard());
    ProcessFaker::assertRan("brew install {$tool->value}");

    packageManagerInstaller($tool)->update(VersionConstraint::wildcard());
    ProcessFaker::assertRan("brew upgrade {$tool->value}");
})->with([
    'bun' => [Tool::Bun],
    'yarn' => [Tool::Yarn],
    'pnpm' => [Tool::Pnpm],
]);

test('npm installs and updates itself through npm', function (): void {
    ProcessFaker::fake();

    packageManagerInstaller(Tool::Npm)->install(VersionConstraint::wildcard());
    packageManagerInstaller(Tool::Npm)->update(VersionConstraint::wildcard());

    ProcessFaker::assertRanTimes('npm install -g npm', 2);
    ProcessFaker::assertDidntRun('brew*');
});
