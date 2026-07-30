<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Enums\ServeStage;

/**
 * One configured serve-pipeline entry, parsed and annotated with its stage
 * and a human label for the progress bar.
 */
final readonly class StepDescriptor
{
    /**
     * @param  string  $entry  the raw config value, e.g. "RunDeployTasks:before"
     * @param  class-string<Step>  $class
     * @param  list<string>  $parameters  the ":variant" arguments, e.g. ["before"]
     */
    public function __construct(
        public int $index,
        public string $entry,
        public string $class,
        public array $parameters,
        public ServeStage $stage,
        public string $label,
    ) {}
}
