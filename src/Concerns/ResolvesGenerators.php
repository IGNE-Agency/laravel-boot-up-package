<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

/**
 * The shared registry shape for generator-backed generate:* commands:
 * built-ins merged UNDER the published config's map (project entries win
 * on key collision), and a key => label map for the interactive picker.
 *
 * @phpstan-require-extends \Illuminate\Console\Command
 */
trait ResolvesGenerators
{
    use ValidatesConfig;

    /**
     * @param  array<string, class-string>  $builtIn
     * @param  array<string, class-string>  $configured
     * @param  class-string  $contract
     * @return array<string, class-string>
     */
    private function mergeGenerators(array $builtIn, array $configured, string $key, string $contract): array
    {
        return array_merge($builtIn, self::validatedClassMap($configured, $key, $contract));
    }

    /**
     * Every generator is instantiated to read its label, so a bad class here
     * breaks the picker even for a provider the user is not choosing --
     * mergeGenerators() validates the configured map before it gets this far.
     *
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
