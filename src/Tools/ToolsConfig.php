<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools;

use Illuminate\Contracts\Config\Repository;

final readonly class ToolsConfig
{
    /**
     * @param  array<string, string>  $required  tool id => semver constraint ('*' = presence only)
     * @param  array<string, class-string<InstallsTool>>  $installers  project overrides; win over built-ins
     */
    public function __construct(
        public bool $autoInstall,
        public bool $autoUpdate,
        public array $required,
        public array $installers,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            autoInstall: (bool) $config->get('boot-up.tools.auto_install', true),
            autoUpdate: (bool) $config->get('boot-up.tools.auto_update', true),
            required: (array) $config->get('boot-up.tools.required', ['php' => '*', 'node' => '*', 'composer' => '*']),
            installers: (array) $config->get('boot-up.tools.installers', []),
        );
    }
}
