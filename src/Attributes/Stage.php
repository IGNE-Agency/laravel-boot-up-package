<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Attributes;

use Attribute;
use Igne\LaravelBootUp\Enums\BootStage;

/**
 * The boot stage a pipeline step belongs to — StepSequence reads it to
 * place the stage divider. A step without one inherits the stage it is
 * slotted into in the configured order.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Stage
{
    public function __construct(public BootStage $stage) {}
}
