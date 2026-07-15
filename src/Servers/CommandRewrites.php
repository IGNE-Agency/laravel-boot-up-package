<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

/**
 * Server-specific command rewrite rules, supplied by each driver.
 */
final readonly class CommandRewrites
{
    /**
     * @param  array<string, string>  $replaces  leading token sequences to swap, e.g. 'php artisan' => 'artisan'
     * @param  list<string>  $prefixes  leading binaries that receive the prefix
     */
    public function __construct(
        public array $replaces = [],
        public array $prefixes = [],
        public ?string $prefix = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
