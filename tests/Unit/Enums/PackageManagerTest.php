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
        // Yarn 2+ has no `update`; `up -R` stays within the manifest's ranges.
        ->and(PackageManager::Yarn->updateCommand())->toBe(['yarn', 'up', '-R', '**']);
});

test('yarn and pnpm come from Corepack, bun and npm do not', function (): void {
    expect(PackageManager::Yarn->usesCorepack())->toBeTrue()
        ->and(PackageManager::Pnpm->usesCorepack())->toBeTrue()
        ->and(PackageManager::Bun->usesCorepack())->toBeFalse()
        ->and(PackageManager::Npm->usesCorepack())->toBeFalse();
});

test('generated scripts invoke every manager without a global install', function (PackageManager $manager, string $binary): void {
    expect($manager->scriptBinary())->toBe($binary);
})->with([
    'npm' => [PackageManager::Npm, 'npm'],
    'yarn' => [PackageManager::Yarn, 'npx --yes corepack yarn'],
    'pnpm' => [PackageManager::Pnpm, 'npx --yes corepack pnpm'],
    'bun' => [PackageManager::Bun, 'npx --yes bun'],
]);

test('ciInstallLine is lockfile-strict with a plain-install fallback', function (PackageManager $manager, string $line): void {
    expect($manager->ciInstallLine())->toBe($line);
})->with([
    'npm' => [PackageManager::Npm, 'npm ci || npm install'],
    'yarn' => [PackageManager::Yarn, 'yarn install --immutable || yarn install'],
    'pnpm' => [PackageManager::Pnpm, 'pnpm install --frozen-lockfile || pnpm install'],
    'bun' => [PackageManager::Bun, 'bun install --frozen-lockfile || bun install'],
]);

test('ciInstallLine uses the given binary on both sides of the fallback', function (): void {
    expect(PackageManager::Yarn->ciInstallLine('npx --yes corepack yarn'))
        ->toBe('npx --yes corepack yarn install --immutable || npx --yes corepack yarn install');
});

test('buildScriptLines installs and builds through the install-free invocation', function (): void {
    expect(PackageManager::Bun->buildScriptLines()->toArray())->toBe([
        'npx --yes bun install --frozen-lockfile || npx --yes bun install',
        'npx --yes bun run build',
    ])
        ->and(PackageManager::Npm->buildScriptLines()->toArray())->toBe([
            'npm ci || npm install',
            'npm run build',
        ])
        ->and(PackageManager::Yarn->buildScriptLines('4.10.3')->toArray())->toBe([
            'npx --yes corepack yarn install --immutable || npx --yes corepack yarn install',
            'npx --yes corepack yarn run build',
        ]);
});

test('no manager is ever installed globally by a generated script', function (PackageManager $manager): void {
    expect(implode("\n", $manager->buildScriptLines('1.0.0')->toArray()))->not->toContain(' -g ');
})->with(PackageManager::cases());

test('an unpinned yarn warns that Corepack would fall back to Yarn 1', function (): void {
    expect(PackageManager::Yarn->buildScriptLines()->toArray())->toBe([
        '#! package.json pins no Yarn version ("packageManager"), so Corepack runs Yarn 1 here —',
        '#! run `npx corepack use yarn@4` locally and commit package.json.',
        'npx --yes corepack yarn install --immutable || npx --yes corepack yarn install',
        'npx --yes corepack yarn run build',
    ])
        // Only Yarn has the 1.x-versus-2+ split; an unpinned pnpm gets Corepack's default.
        ->and(implode("\n", PackageManager::Pnpm->buildScriptLines()->toArray()))->not->toContain('#!');
});
