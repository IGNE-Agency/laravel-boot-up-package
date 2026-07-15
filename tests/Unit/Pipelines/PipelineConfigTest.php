<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Pipelines\PipelineConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the bootstrap.pipeline schema', function (): void {
    $config = new Repository([
        'bootstrap' => [
            'pipeline' => [
                'branches' => ['main' => 'PROD_DEPLOY'],
                'generators' => ['gitlab' => 'App\Pipelines\GitlabGenerator'],
            ],
        ],
    ]);

    $pipeline = PipelineConfig::fromRepository($config);

    expect($pipeline->branchHooks)->toBe(['main' => 'PROD_DEPLOY'])
        ->and($pipeline->generators)->toBe(['gitlab' => 'App\Pipelines\GitlabGenerator']);
});

test('fromRepository falls back to the documented defaults', function (): void {
    $pipeline = PipelineConfig::fromRepository(new Repository);

    expect($pipeline->branchHooks)->toBe([
        'develop' => 'DEV_DEPLOY',
        'staging' => 'STAGING_DEPLOY',
        'master' => 'PROD_DEPLOY',
    ])->and($pipeline->generators)->toBe([]);
});
