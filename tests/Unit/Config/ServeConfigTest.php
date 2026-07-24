<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ServeConfig;
use Illuminate\Config\Repository;

test('reads its keys from the boot-up config schema', function (): void {
    $config = ServeConfig::fromRepository(new Repository([
        'boot-up' => [
            'serve_steps' => ['StepA', 'StepB'],
            'deploy_steps' => ['StepC'],
            'browser' => ['open' => false],
        ],
    ]));

    expect($config->serveSteps)->toBe(['StepA', 'StepB'])
        ->and($config->deploySteps)->toBe(['StepC'])
        ->and($config->openBrowser)->toBeFalse();
});

test('the shipped config file provides every key this reader consumes', function (): void {
    $shipped = require dirname(__DIR__, 3).'/config/boot-up.php';

    $config = ServeConfig::fromRepository(new Repository(['boot-up' => $shipped]));

    expect($config->serveSteps)->not->toBeEmpty()
        ->and($config->deploySteps)->not->toBeEmpty()
        ->and($config->openBrowser)->toBeTrue();
});
