<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ServeConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.serve schema', function (): void {
    $config = ServeConfig::fromRepository(new Repository([
        'boot-up' => [
            'serve' => [
                'steps' => ['StepA', 'StepB'],
                'open_browser' => false,
                'auto_accept' => true,
            ],
        ],
    ]));

    expect($config->steps)->toBe(['StepA', 'StepB'])
        ->and($config->openBrowser)->toBeFalse()
        ->and($config->autoAccept)->toBeTrue();
});
