<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Composer\Semver\VersionParser;
use Igne\LaravelBootUp\Data\StepEntry;
use Igne\LaravelBootUp\Exceptions\ConfigException;
use Throwable;

/**
 * Checks for config that is only wrong once something tries to use it.
 *
 * A class name that does not exist, or exists but implements the wrong
 * contract, fails somewhere far from the key that named it — and a step class
 * fails after the plan was already confirmed and earlier steps may have
 * written .env or run migrations. An out-of-range number is worse still: a
 * zero attempt count reads as "gave up" rather than "never tried". Every check
 * here names the key it came from.
 */
trait ValidatesConfig
{
    /**
     * @param  list<string>  $entries  raw pipeline entries, "Class" or "Class:a,b"
     * @param  class-string  $contract
     * @return list<string>
     */
    private static function validatedSteps(array $entries, string $key, string $contract): array
    {
        foreach ($entries as $entry) {
            self::validatedClass(StepEntry::parse((string) $entry)->class, $key, $contract);
        }

        return array_values($entries);
    }

    /**
     * @param  array<array-key, string>  $map  a driver/installer/generator map
     * @param  class-string  $contract
     * @return array<array-key, string>
     */
    private static function validatedClassMap(array $map, string $key, string $contract): array
    {
        foreach ($map as $class) {
            self::validatedClass((string) $class, $key, $contract);
        }

        return $map;
    }

    /**
     * @param  class-string  $contract
     */
    private static function validatedClass(string $class, string $key, string $contract): string
    {
        if (! class_exists($class)) {
            throw ConfigException::missingClass($key, $class);
        }

        if (! is_a($class, $contract, allow_string: true)) {
            throw ConfigException::wrongContract($key, $class, $contract);
        }

        return $class;
    }

    /**
     * Version constraints are checked here rather than where they are compared:
     * VersionConstraint treats anything it cannot parse as satisfied, on the
     * principle that a version boot-up fails to read must not block the boot.
     * That is right for a tool's reported version and wrong for a constraint
     * from config, where it means the constraint is quietly ignored forever.
     *
     * @param  array<string, string>  $constraints
     * @return array<string, string>
     */
    private static function validatedConstraints(array $constraints, string $key): array
    {
        $parser = new VersionParser;

        foreach ($constraints as $tool => $constraint) {
            $value = (string) $constraint;

            if ($value === '*' || $value === '') {
                continue;
            }

            try {
                $parser->parseConstraints($value);
            } catch (Throwable) {
                throw ConfigException::invalidType("{$key}.{$tool}", "[{$value}]", 'a version constraint such as ^8.3');
            }
        }

        return $constraints;
    }

    private static function atLeast(mixed $value, int $minimum, string $key): int
    {
        $number = (int) $value;

        if ($number < $minimum) {
            throw ConfigException::outOfRange($key, $number, "at least {$minimum}");
        }

        return $number;
    }

    private static function withinRange(mixed $value, int $minimum, int $maximum, string $key): int
    {
        $number = (int) $value;

        if ($number < $minimum || $number > $maximum) {
            throw ConfigException::outOfRange($key, $number, "a number between {$minimum} and {$maximum}");
        }

        return $number;
    }
}
