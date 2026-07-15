<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Tools\ToolsConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.tools schema', function (): void {
    $config = new Repository([
        'boot-up' => [
            'tools' => [
                'auto_install' => false,
                'auto_update' => false,
                'required' => ['php' => '^8.3', 'mytool' => '*'],
                'installers' => ['mytool' => 'App\\Support\\MyToolInstaller'],
            ],
        ],
    ]);

    $tools = ToolsConfig::fromRepository($config);

    expect($tools->autoInstall)->toBeFalse()
        ->and($tools->autoUpdate)->toBeFalse()
        ->and($tools->required)->toBe(['php' => '^8.3', 'mytool' => '*'])
        ->and($tools->installers)->toBe(['mytool' => 'App\\Support\\MyToolInstaller']);
});

test('fromRepository falls back to the documented defaults', function (): void {
    $tools = ToolsConfig::fromRepository(new Repository);

    expect($tools->autoInstall)->toBeTrue()
        ->and($tools->autoUpdate)->toBeTrue()
        ->and($tools->required)->toBe(['php' => '*', 'node' => '*', 'composer' => '*'])
        ->and($tools->installers)->toBe([]);
});
