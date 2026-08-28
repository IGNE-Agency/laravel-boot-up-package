<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Concerns\ValidatesConfig;
use Illuminate\Contracts\Config\Repository;

/**
 * How patient boot-up is with the OS processes it owns.
 */
final readonly class ProcessConfig
{
    use ValidatesConfig;

    /**
     * @param  int  $termGraceSeconds  how long a process may take to honour SIGTERM before SIGKILL
     * @param  int  $killGraceSeconds  how long it may take to disappear after SIGKILL
     * @param  int  $installTimeoutSeconds  ceiling for a dependency install, which is minutes on a slow network
     */
    public function __construct(
        public int $termGraceSeconds = 5,
        public int $killGraceSeconds = 2,
        public int $installTimeoutSeconds = 1800,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            termGraceSeconds: self::intAtLeast($config, 'boot-up.process.term_grace_seconds', 5, 0),
            killGraceSeconds: self::intAtLeast($config, 'boot-up.process.kill_grace_seconds', 2, 0),
            installTimeoutSeconds: self::intAtLeast($config, 'boot-up.process.install_timeout_seconds', 1800, 1),
        );
    }
}
