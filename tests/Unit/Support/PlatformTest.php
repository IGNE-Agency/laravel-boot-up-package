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
