<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\HorizonConfig;
use Igne\LaravelBootUp\Enums\RunMode;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.horizon schema', function (): void {
    $config = HorizonConfig::fromRepository(new Repository([
        'boot-up' => [
            'horizon' => ['enabled' => false, 'run_in' => 'background'],
        ],
    ]));

    expect($config->enabled)->toBeFalse()
        ->and($config->runIn)->toBe(RunMode::Background);
});
