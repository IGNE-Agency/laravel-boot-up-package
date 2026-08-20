<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DevConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.dev schema', function (): void {
    $config = DevConfig::fromRepository(new Repository([
        'boot-up' => [
            'dev' => [
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
