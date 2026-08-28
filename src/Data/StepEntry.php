<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Contracts\Step;

/**
 * One entry from a configured pipeline, split into the class and its
 * arguments: "RunDeployTasks:before" becomes the class plus ["before"].
 *
 * Mirrors Illuminate\Pipeline\Pipeline::parsePipeString(), which is protected
 * upstream. Both the plan builder and the config validation need the same
 * split, so it lives here rather than in either of them.
 */
final readonly class StepEntry
{
    /**
     * @param  class-string<Step>|string  $class
     * @param  list<string>  $parameters
     */
    private function __construct(
        public string $class,
        public array $parameters,
    ) {}

    public static function parse(string $entry): self
    {
        [$class, $parameters] = array_pad(explode(':', $entry, 2), 2, null);

        return new self(
            (string) $class,
            $parameters === null ? [] : explode(',', $parameters),
        );
    }
}
