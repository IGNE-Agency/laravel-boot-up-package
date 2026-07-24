<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Igne\LaravelBootUp\Exceptions\ConsoleException;
use LogicException;

/**
 * The standard argument-or-select flow, resolved by naming convention:
 * choose('provider', ...) reads the provider argument and takes its
 * options from the command's providerOptions() method — the same way
 * Eloquent resolves where{Column}. A command adding a choice is forced
 * to define its options; validation and the unknown-choice error come
 * with it for free.
 */
trait PromptsForChoice
{
    /**
     * Resolve the {$name} argument against "{$name}Options()", prompting
     * with $question when the argument is absent. A supplied argument is
     * lowercased and must match an option key; an unknown one aborts the
     * command with the available keys. The options method must be at least
     * protected — it is called from the base command's scope.
     */
    protected function choose(string $name, string $question, ?string $default = null): string
    {
        $options = $this->choiceOptions($name);
        $argument = $this->argument($name);

        if (\is_string($argument) && $argument !== '') {
            $choice = strtolower($argument);

            if (! array_key_exists($choice, $options)) {
                throw ConsoleException::unknownChoice($name, $choice, array_keys($options));
            }

            return $choice;
        }

        return (string) terminal()->select($question, $options, $default);
    }

    /**
     * @return array<string, string> option key => human label
     */
    private function choiceOptions(string $name): array
    {
        $method = "{$name}Options";

        if (! method_exists($this, $method)) {
            $command = static::class;

            throw new LogicException("{$command} must define {$method}() to choose a {$name}.");
        }

        return $this->{$method}();
    }
}
