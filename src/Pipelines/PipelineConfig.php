<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

use Illuminate\Contracts\Config\Repository;

final readonly class PipelineConfig
{
    public const DEFAULT_BRANCH_ENVIRONMENTS = [
        'develop' => 'development',
        'staging' => 'staging',
        'main' => 'production',
    ];

    /**
     * @param  array<string, string>  $branchEnvironments  git branch => deployment environment name
     * @param  array<string, class-string>  $generators  provider key => PipelineGenerator class; wins over built-ins
     * @param  array<mixed>  $steps  raw extra-step config, validated per run against the chosen provider
     * @param  array<mixed>  $files  raw extra-file config
     */
    public function __construct(
        public array $branchEnvironments = self::DEFAULT_BRANCH_ENVIRONMENTS,
        public array $generators = [],
        public array $steps = [],
        public array $files = [],
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            branchEnvironments: (array) $config->get('boot-up.pipeline.branches', self::DEFAULT_BRANCH_ENVIRONMENTS),
            generators: (array) $config->get('boot-up.pipeline.generators', []),
            steps: (array) $config->get('boot-up.pipeline.steps', []),
            files: (array) $config->get('boot-up.pipeline.files', []),
        );
    }
}
