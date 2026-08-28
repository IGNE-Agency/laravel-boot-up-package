<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Concerns\ValidatesConfig;
use Illuminate\Contracts\Config\Repository;

final readonly class HerdConfig
{
    use ValidatesConfig;

    /**
     * @param  string|null  $site  fixed Herd site name; null prompts with the folder name as default
     */
    public function __construct(
        public ?string $site = null,
        public int $healthAttempts = 10,
        public int $healthDelayMs = 500,
        public int $healthTimeoutSeconds = 5,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        $site = $config->get('boot-up.herd.site');

        return new self(
            site: $site === null ? null : (string) $site,
            healthAttempts: self::intAtLeast($config, 'boot-up.herd.health.attempts', 10, 1),
            healthDelayMs: self::intAtLeast($config, 'boot-up.herd.health.delay_ms', 500, 0),
            healthTimeoutSeconds: self::intAtLeast($config, 'boot-up.herd.health.timeout_seconds', 5, 1),
        );
    }
}
