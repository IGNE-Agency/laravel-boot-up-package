<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Attributes;

use Attribute;

/**
 * The plan-summary group a pipeline step merges into — steps sharing a
 * group produce one "What dev will do" line, emitted at the group's
 * first occurrence. A step without one gets its own line.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Group
{
    public function __construct(public string $name) {}
}
