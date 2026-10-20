<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

/**
 * Capability: the platform reads its deployment settings from a file
 * committed to the repository (e.g. fortrabbit's .fortrabbit/deploy.yml).
 * generate:deploy-script writes generate()'s output there by default;
 * --in-terminal prints paste-ready commands instead, rendered for a plan
 * whose inTerminal flag is set.
 */
interface WritesDeployFile
{
    /**
     * @return string the deploy file's path, relative to the repository root
     */
    public function deployFilePath(): string;
}
