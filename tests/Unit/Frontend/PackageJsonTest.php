<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Frontend\PackageJson;

function packageJsonWith(array $contents): PackageJson
{
    $dir = sys_get_temp_dir().'/boot-up-package-json-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/package.json', json_encode($contents));

    return new PackageJson($dir.'/package.json');
}

test('pinnedVersion reads the version the packageManager field pins', function (string $pin, string $version): void {
    expect(packageJsonWith(['packageManager' => $pin])->pinnedVersion(PackageManager::Yarn))->toBe($version);
})->with([
    'with an integrity hash' => ['yarn@4.10.3+sha512.c38cafb5c7bb273f3926d04e55e1d8c9dfa7d9c3ea1f36a4868fa028b9e5f72298f0b7f401ad5eb921749eb012eb1c3bb74bf7503df3ee43fd600d14a018266f', '4.10.3'],
    'without a hash' => ['yarn@4.10.3', '4.10.3'],
    'a prerelease' => ['yarn@4.0.0-rc.53', '4.0.0-rc.53'],
]);

test('pinnedVersion is null when the field pins another manager', function (): void {
    expect(packageJsonWith(['packageManager' => 'pnpm@10.12.1'])->pinnedVersion(PackageManager::Yarn))->toBeNull()
        ->and(packageJsonWith(['packageManager' => 'pnpm@10.12.1'])->pinnedVersion(PackageManager::Pnpm))->toBe('10.12.1');
});

test('pinnedVersion is null without a packageManager field or package.json', function (): void {
    expect(packageJsonWith(['name' => 'app'])->pinnedVersion(PackageManager::Yarn))->toBeNull()
        ->and((new PackageJson(sys_get_temp_dir().'/boot-up-missing-'.bin2hex(random_bytes(4)).'/package.json'))->pinnedVersion(PackageManager::Yarn))->toBeNull();
});

test('pinnedVersion ignores a non-string packageManager field', function (): void {
    expect(packageJsonWith(['packageManager' => ['yarn' => '4']])->pinnedVersion(PackageManager::Yarn))->toBeNull();
});
