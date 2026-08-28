<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ReverbConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.reverb schema', function (): void {
    $config = ReverbConfig::fromRepository(new Repository([
        'boot-up' => [
            'reverb' => ['enabled' => false],
        ],
    ]));

    expect($config->enabled)->toBeFalse();
});
