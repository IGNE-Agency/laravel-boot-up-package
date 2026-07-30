<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\SchedulerConfig;
use Igne\LaravelBootUp\Enums\RunMode;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.scheduler schema', function (): void {
    $config = SchedulerConfig::fromRepository(new Repository([
        'boot-up' => [
            'scheduler' => ['enabled' => true, 'run_in' => 'background'],
        ],
    ]));

    expect($config->enabled)->toBeTrue()
        ->and($config->runIn)->toBe(RunMode::Background);
});
