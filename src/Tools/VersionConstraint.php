<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools;

use Composer\Semver\Semver;
use Throwable;

/**
 * A composer-style semver constraint ('^8.3', '>=20', ...). '*' or an empty
 * string means "any version" — presence of the tool is enough.
 */
final readonly class VersionConstraint
{
    private function __construct(public string $value) {}

    public static function of(string $constraint): self
    {
        return new self(trim($constraint));
    }

    public static function wildcard(): self
    {
        return new self('*');
    }

    public function isWildcard(): bool
    {
        return $this->value === '*' || $this->value === '';
    }

    /**
     * Unparseable versions or constraints count as satisfied: a version we
     * cannot understand must never block the boot.
     */
    public function isSatisfiedBy(string $version): bool
    {
        if ($this->isWildcard()) {
            return true;
        }

        try {
            return Semver::satisfies($version, $this->value);
        } catch (Throwable) {
            return true;
        }
    }
}
