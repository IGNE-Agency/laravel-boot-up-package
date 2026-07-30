<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ReverbConfig;
use Igne\LaravelBootUp\Enums\RunMode;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.reverb schema', function (): void {
    $config = ReverbConfig::fromRepository(new Repository([
        'boot-up' => [
            'reverb' => ['enabled' => false, 'run_in' => 'terminal'],
        ],
    ]));

    expect($config->enabled)->toBeFalse()
        ->and($config->runIn)->toBe(RunMode::Terminal);
});
