<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Igne\LaravelBootstrap\Tools\Installers\PackageManagerInstaller;
use Igne\LaravelBootstrap\Tools\Tool;
use Igne\LaravelBootstrap\Tools\VersionConstraint;

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
    'bun' => [Tool::BUN],
    'yarn' => [Tool::YARN],
    'pnpm' => [Tool::PNPM],
]);

test('npm installs and updates itself through npm', function (): void {
    ProcessFaker::fake();

    packageManagerInstaller(Tool::NPM)->install(VersionConstraint::wildcard());
    packageManagerInstaller(Tool::NPM)->update(VersionConstraint::wildcard());

    ProcessFaker::assertRanTimes('npm install -g npm', 2);
    ProcessFaker::assertDidntRun('brew*');
});
