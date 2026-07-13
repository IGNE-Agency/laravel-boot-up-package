<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Frontend\PackageManager;
use Igne\LaravelBootstrap\Tools\Tool;

test('every package manager knows its binary, lockfile, commands and tool', function (
    PackageManager $manager,
    string $lockfile,
    Tool $tool,
): void {
    expect($manager->binary())->toBe($manager->value)
        ->and($manager->lockfile())->toBe($lockfile)
        ->and($manager->installCommand())->toBe([$manager->value, 'install'])
        ->and($manager->runCommand('build'))->toBe([$manager->value, 'run', 'build'])
        ->and($manager->tool())->toBe($tool);
})->with([
    'bun' => [PackageManager::BUN, 'bun.lock', Tool::BUN],
    'yarn' => [PackageManager::YARN, 'yarn.lock', Tool::YARN],
    'npm' => [PackageManager::NPM, 'package-lock.json', Tool::NPM],
    'pnpm' => [PackageManager::PNPM, 'pnpm-lock.yaml', Tool::PNPM],
]);

test('the please-use engines sentinel resolves pnpm', function (): void {
    $file = sys_get_temp_dir().'/bootstrap-pkg-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($file, json_encode(['engines' => ['node' => 'please-use-pnpm']]));

    expect((new Igne\LaravelBootstrap\Frontend\PackageJson($file))->demandedPackageManager())
        ->toBe(PackageManager::PNPM);

    @unlink($file);
});

test('update commands', function (): void {
    expect(PackageManager::NPM->updateCommand())->toBe(['npm', 'update'])
        ->and(PackageManager::PNPM->updateCommand())->toBe(['pnpm', 'update'])
        ->and(PackageManager::BUN->updateCommand())->toBe(['bun', 'update'])
        ->and(PackageManager::YARN->updateCommand())->toBe(['yarn', 'update']);
});
