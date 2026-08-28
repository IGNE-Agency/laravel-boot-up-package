<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Igne\LaravelBootUp\Exceptions\ConsoleException;

/**
 * The standard argument-or-select flow: read the named argument, validate
 * it against the given options, and prompt when it is absent. Validation
 * and the unknown-choice error come with it for free.
 *
 * @phpstan-require-extends \Illuminate\Console\Command
 */
trait PromptsForChoice
{
    /**
     * Resolve the {$name} argument against $options, prompting with
     * $question when the argument is absent. A supplied argument is
     * lowercased and must match an option key; an unknown one aborts the
     * command with the available keys.
     *
     * @param  array<string, string>  $options  option key => human label
     */
    protected function choose(string $name, string $question, array $options, ?string $default = null): string
    {
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
}
