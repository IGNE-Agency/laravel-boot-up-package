<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Tools;

use Igne\LaravelBootstrap\Tools\Installers\ComposerInstaller;
use Igne\LaravelBootstrap\Tools\Installers\DockerInstaller;
use Igne\LaravelBootstrap\Tools\Installers\HerdInstaller;
use Igne\LaravelBootstrap\Tools\Installers\NodeInstaller;
use Igne\LaravelBootstrap\Tools\Installers\PackageManagerInstaller;
use Igne\LaravelBootstrap\Tools\Installers\PhpInstaller;
use Illuminate\Contracts\Container\Container;

/**
 * Maps a tool id to its installer. Project overrides from
 * config('bootstrap.tools.installers') win over the built-ins.
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
            Tool::PHP => $this->container->make(PhpInstaller::class),
            Tool::NODE => $this->container->make(NodeInstaller::class),
            Tool::COMPOSER => $this->container->make(ComposerInstaller::class),
            Tool::DOCKER => $this->container->make(DockerInstaller::class),
            Tool::HERD => $this->container->make(HerdInstaller::class),
            Tool::BUN,
            Tool::YARN,
            Tool::NPM => $this->container->make(PackageManagerInstaller::class, ['tool' => Tool::from($id)]),
            default => throw ToolException::unknownTool($id),
        };
    }
}
