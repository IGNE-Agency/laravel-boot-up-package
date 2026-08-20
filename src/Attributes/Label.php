<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Attributes;

use Attribute;

/**
 * What the progress bar calls this step while it runs.
 *
 * The plan is built from class strings before anything is resolved, so the
 * label has to be readable without instantiating the step — hence an
 * attribute. A step whose wording depends on the run's options implements
 * Contracts\DescribesProgress instead; one that carries neither is named
 * after its class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Label
{
    public function __construct(public string $text) {}
}
