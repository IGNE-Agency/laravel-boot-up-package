<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\PipelineConfig;
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

test('environment names are lowercased so any case in the config resolves the same environment', function (): void {
    $config = new Repository([
        'boot-up' => [
            'pipeline' => [
                'branches' => ['develop' => 'Development', 'staging' => 'STAGING', 'main' => 'Production'],
            ],
        ],
    ]);

    $pipeline = PipelineConfig::fromRepository($config);

    // Branch keys stay verbatim (git branches are case-sensitive); the
    // environment values are normalized to lowercase.
    expect($pipeline->branchEnvironments)->toBe([
        'develop' => 'development',
        'staging' => 'staging',
        'main' => 'production',
    ]);
});

test('fromRepository falls back to the documented defaults', function (): void {
    $pipeline = PipelineConfig::fromRepository(new Repository);

    expect($pipeline->branchEnvironments)->toBe([
        'develop' => 'development',
        'staging' => 'staging',
        'main' => 'production',
    ])->and($pipeline->generators)->toBe([]);
});
