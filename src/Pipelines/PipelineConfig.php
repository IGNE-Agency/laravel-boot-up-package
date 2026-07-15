<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

use Illuminate\Contracts\Config\Repository;

final readonly class PipelineConfig
{
    public const DEFAULT_BRANCH_HOOKS = [
        'develop' => 'DEV_DEPLOY',
        'staging' => 'STAGING_DEPLOY',
        'master' => 'PROD_DEPLOY',
    ];

    /**
     * @param  array<string, string>  $branchHooks  git branch => deploy-hook secret/variable name
     * @param  array<string, class-string>  $generators  provider key => PipelineGenerator class; wins over built-ins
     */
    public function __construct(
        public array $branchHooks = self::DEFAULT_BRANCH_HOOKS,
        public array $generators = [],
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            branchHooks: (array) $config->get('boot-up.pipeline.branches', self::DEFAULT_BRANCH_HOOKS),
            generators: (array) $config->get('boot-up.pipeline.generators', []),
        );
    }
}
