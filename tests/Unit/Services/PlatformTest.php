<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\OperatingSystem;
use Igne\LaravelBootUp\Services\Platform;

test('each family answers exactly one check', function (): void {
    $mac = new Platform(OperatingSystem::Darwin);
    $linux = new Platform(OperatingSystem::Linux);
    $windows = new Platform(OperatingSystem::Windows);

    expect($mac->isMacos())->toBeTrue()
        ->and($mac->isLinux())->toBeFalse()
        ->and($mac->isWindows())->toBeFalse()
        ->and($linux->isLinux())->toBeTrue()
        ->and($linux->isMacos())->toBeFalse()
        ->and($windows->isWindows())->toBeTrue()
        ->and($windows->isMacos())->toBeFalse();
});
