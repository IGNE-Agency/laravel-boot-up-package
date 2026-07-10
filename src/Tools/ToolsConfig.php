<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Tools;

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
            autoInstall: (bool) $config->get('bootstrap.tools.auto_install', true),
            autoUpdate: (bool) $config->get('bootstrap.tools.auto_update', true),
            required: (array) $config->get('bootstrap.tools.required', ['php' => '*', 'node' => '*', 'composer' => '*']),
            installers: (array) $config->get('bootstrap.tools.installers', []),
        );
    }
}
