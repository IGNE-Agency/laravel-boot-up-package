<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Enums\Tool;

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
    'bun' => [PackageManager::Bun, 'bun.lock', Tool::Bun],
    'yarn' => [PackageManager::Yarn, 'yarn.lock', Tool::Yarn],
    'npm' => [PackageManager::Npm, 'package-lock.json', Tool::Npm],
    'pnpm' => [PackageManager::Pnpm, 'pnpm-lock.yaml', Tool::Pnpm],
]);

test('the default is bun', function (): void {
    expect(PackageManager::default())->toBe(PackageManager::Bun);
});

test('the please-use engines sentinel resolves pnpm', function (): void {
    $file = sys_get_temp_dir().'/boot-up-pkg-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($file, json_encode(['engines' => ['node' => 'please-use-pnpm']]));

    expect((new Igne\LaravelBootUp\Frontend\PackageJson($file))->demandedPackageManager())
        ->toBe(PackageManager::Pnpm);

    @unlink($file);
});

test('exec commands', function (): void {
    expect(PackageManager::Bun->execCommand())->toBe(['bunx'])
        ->and(PackageManager::Npm->execCommand())->toBe(['npx'])
        ->and(PackageManager::Pnpm->execCommand())->toBe(['pnpm', 'exec'])
        ->and(PackageManager::Yarn->execCommand())->toBe(['yarn', 'exec']);
});

test('update commands', function (): void {
    expect(PackageManager::Npm->updateCommand())->toBe(['npm', 'update'])
        ->and(PackageManager::Pnpm->updateCommand())->toBe(['pnpm', 'update'])
        ->and(PackageManager::Bun->updateCommand())->toBe(['bun', 'update'])
        ->and(PackageManager::Yarn->updateCommand())->toBe(['yarn', 'update']);
});

test('buildScriptLines renders the shared frontend block', function (): void {
    expect(PackageManager::Bun->buildScriptLines(ensureInstalled: true)->toArray())->toBe([
        'npm i -g bun',
        'bun install --frozen-lockfile || bun install',
        'bun run build',
    ])
        ->and(PackageManager::Npm->buildScriptLines(ensureInstalled: true)->toArray())->toBe([
            'npm ci || npm install',
            'npm run build',
        ])
        ->and(PackageManager::Bun->buildScriptLines(ensureInstalled: false)->toArray())->toBe([
            'bun install --frozen-lockfile || bun install',
            'bun run build',
        ]);
});
