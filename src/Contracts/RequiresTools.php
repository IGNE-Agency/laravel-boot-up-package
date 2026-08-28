<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Enums\Tool;

/**
 * Capability: the server needs host tools present before it can start
 * (e.g. Sail needs Docker). The Tools step installs what is missing.
 */
interface RequiresTools
{
    /**
     * @return list<Tool> tools that must be present on the host before start() runs
     */
    public function requiredTools(): array;
}
