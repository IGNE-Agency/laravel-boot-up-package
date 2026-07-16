<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Support\Platform;

test('each family answers exactly one check', function (): void {
    $mac = new Platform('Darwin');
    $linux = new Platform('Linux');
    $windows = new Platform('Windows');

    expect($mac->isMacos())->toBeTrue()
        ->and($mac->isLinux())->toBeFalse()
        ->and($mac->isWindows())->toBeFalse()
        ->and($linux->isLinux())->toBeTrue()
        ->and($linux->isMacos())->toBeFalse()
        ->and($windows->isWindows())->toBeTrue()
        ->and($windows->isMacos())->toBeFalse();
});

test('defaults to the family PHP itself reports', function (): void {
    $platform = new Platform;

    expect($platform->isMacos())->toBe(PHP_OS_FAMILY === 'Darwin')
        ->and($platform->isLinux())->toBe(PHP_OS_FAMILY === 'Linux')
        ->and($platform->isWindows())->toBe(PHP_OS_FAMILY === 'Windows');
});
