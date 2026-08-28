<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Contracts\PipelineGenerator;
use Illuminate\Contracts\Config\Repository;

final readonly class PipelineConfig
{
    public const array DEFAULT_BRANCH_ENVIRONMENTS = [
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
        public ?bool $composerAuth = null,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        $composerAuth = $config->get('boot-up.pipeline.composer_auth');

        return new self(
            branchEnvironments: self::normalizeEnvironments(
                (array) $config->get('boot-up.pipeline.branches', self::DEFAULT_BRANCH_ENVIRONMENTS),
            ),
            generators: (array) $config->get('boot-up.pipeline.generators', []),
            steps: (array) $config->get('boot-up.pipeline.steps', []),
            files: (array) $config->get('boot-up.pipeline.files', []),
            // null = auto-detect (Nova); an explicit bool forces it on/off.
            composerAuth: $composerAuth === null ? null : (bool) $composerAuth,
        );
    }

    /**
     * Environment names are lowercased so the branch map works whether the
     * config (and the environment the user creates on the git provider) is
     * written lowercase, ucfirst or uppercase — "Development", "DEVELOPMENT"
     * and "development" all resolve to the same environment. Git branch names
     * (the keys) stay verbatim, since those are case-sensitive.
     *
     * @param  array<string, string>  $branchEnvironments
     * @return array<string, string>
     */
    private static function normalizeEnvironments(array $branchEnvironments): array
    {
        return array_map(static fn (string $environment): string => strtolower(trim($environment)), $branchEnvironments);
    }
}
