<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Config\EnvironmentConfig;
use Igne\LaravelBootUp\Environment\ShellProfile;

/**
 * Offers to add the conventional sail alias to the user's shell profile
 * once, at the end of a successful Sail start.
 */
final class SailAliasInstaller
{
    private const string ALIAS = "alias sail='[ -f sail ] && bash sail || bash vendor/bin/sail'";

    public function __construct(
        private readonly ShellProfile $profile,
        private readonly EnvironmentConfig $config,
    ) {}

    public function ensure(): void
    {
        if (! $this->config->manageSailAlias || ! $this->profile->exists() || $this->profile->definesAlias('sail')) {
            return;
        }

        $path = (string) $this->profile->path();

        if (! terminal()->confirm(label: "Add a sail alias to {$path}?")) {
            terminal()->note("Tip: you can add it yourself: {$path} → ".self::ALIAS);

            return;
        }

        $this->profile->appendBlock(self::ALIAS);
        terminal()->success("Sail alias added. Run: source {$path}");
    }
}
