<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Pipelines\PipelineConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.pipeline schema', function (): void {
    $config = new Repository([
        'boot-up' => [
            'pipeline' => [
                'branches' => ['main' => 'production'],
                'generators' => ['gitlab' => 'App\Pipelines\GitlabGenerator'],
            ],
        ],
    ]);

    $pipeline = PipelineConfig::fromRepository($config);

    expect($pipeline->branchEnvironments)->toBe(['main' => 'production'])
        ->and($pipeline->generators)->toBe(['gitlab' => 'App\Pipelines\GitlabGenerator']);
});

test('fromRepository falls back to the documented defaults', function (): void {
    $pipeline = PipelineConfig::fromRepository(new Repository);

    expect($pipeline->branchEnvironments)->toBe([
        'develop' => 'development',
        'staging' => 'staging',
        'main' => 'production',
    ])->and($pipeline->generators)->toBe([]);
});
