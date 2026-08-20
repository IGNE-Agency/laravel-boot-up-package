<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\SchedulerConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.scheduler schema', function (): void {
    $config = SchedulerConfig::fromRepository(new Repository([
        'boot-up' => [
            'scheduler' => ['enabled' => true],
        ],
    ]));

    expect($config->enabled)->toBeTrue();
});
