<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

/**
 * The shared registry shape for generator-backed generate:* commands:
 * built-ins merged UNDER the published config's map (project entries win
 * on key collision), and a key => label map for the interactive picker.
 */
trait ResolvesGenerators
{
    /**
     * @param  array<string, class-string>  $builtIn
     * @param  array<string, class-string>  $configured
     * @return array<string, class-string>
     */
    private function mergeGenerators(array $builtIn, array $configured): array
    {
        return array_merge($builtIn, $configured);
    }

    /**
     * @param  array<string, class-string>  $generators
     * @return array<string, string>
     */
    private function generatorLabels(array $generators): array
    {
        return collect($generators)
            ->map(fn (string $class): string => $this->laravel->make($class)->label())
            ->all();
    }
}
