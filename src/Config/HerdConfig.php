<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;

final readonly class HerdConfig
{
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
            healthAttempts: (int) $config->get('boot-up.herd.health.attempts', 10),
            healthDelayMs: (int) $config->get('boot-up.herd.health.delay_ms', 500),
            healthTimeoutSeconds: (int) $config->get('boot-up.herd.health.timeout_seconds', 5),
        );
    }
}
