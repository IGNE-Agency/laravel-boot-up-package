<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Contracts\InstallsTool;
use Illuminate\Contracts\Config\Repository;

final readonly class ToolsConfig
{
    private const array DEFAULT_REQUIRED = ['php' => '*', 'node' => '*', 'composer' => '*'];

    /**
     * @param  array<string, string>  $required  tool id => semver constraint ('*' = presence only)
     * @param  array<string, class-string<InstallsTool>>  $installers  project overrides; win over built-ins
     */
    public function __construct(
        public bool $autoInstall = true,
        public bool $autoUpdate = true,
        public array $required = self::DEFAULT_REQUIRED,
        public array $installers = [],
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            autoInstall: (bool) $config->get('boot-up.tools.auto_install', true),
            autoUpdate: (bool) $config->get('boot-up.tools.auto_update', true),
            required: (array) $config->get('boot-up.tools.required', self::DEFAULT_REQUIRED),
            installers: (array) $config->get('boot-up.tools.installers', []),
        );
    }
}
