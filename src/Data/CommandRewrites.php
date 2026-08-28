<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Contracts\Server;

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

    /**
     * Whether commands starting with this binary are wrapped by the
     * server (e.g. Sail runs `bun` inside its container), so the binary
     * does not need to exist on the host.
     */
    public function wraps(string $binary): bool
    {
        return $this->prefix !== null && \in_array($binary, $this->prefixes, true);
    }
}
