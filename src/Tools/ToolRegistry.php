<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools;

use Igne\LaravelBootUp\Config\ToolsConfig;
use Igne\LaravelBootUp\Contracts\InstallsTool;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Exceptions\ToolException;
use Igne\LaravelBootUp\Tools\Installers\ComposerInstaller;
use Igne\LaravelBootUp\Tools\Installers\HomebrewInstaller;
use Igne\LaravelBootUp\Tools\Installers\PackageManagerInstaller;
use Igne\LaravelBootUp\Tools\Installers\PhpInstaller;
use Illuminate\Contracts\Container\Container;

/**
 * Maps a tool id to its installer. Project overrides from the tools
 * config (ToolsConfig) win over the built-ins.
 */
final class ToolRegistry
{
    public function __construct(
        private readonly Container $container,
        private readonly ToolsConfig $config,
    ) {}

    public function installerFor(string $id): InstallsTool
    {
        $custom = $this->config->installers[$id] ?? null;

        if ($custom !== null) {
            return $this->container->make($custom);
        }

        return match (Tool::tryFrom($id)) {
            Tool::Php => $this->container->make(PhpInstaller::class),
            Tool::Composer => $this->container->make(ComposerInstaller::class),
            Tool::Node => $this->container->make(HomebrewInstaller::class, ['tool' => Tool::Node]),
            Tool::Docker,
            Tool::Herd => $this->container->make(HomebrewInstaller::class, ['tool' => Tool::from($id), 'cask' => true]),
            Tool::Bun,
            Tool::Yarn,
            Tool::Npm,
            Tool::Pnpm => $this->container->make(PackageManagerInstaller::class, ['tool' => Tool::from($id)]),
            default => throw ToolException::unknownTool($id),
        };
    }
}
