<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;

final readonly class ProcessConfig
{
    /**
     * @param  int  $terminalPidTimeout  seconds to wait for a terminal-window process to report its PID
     */
    public function __construct(
        public int $terminalPidTimeout = 20,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            terminalPidTimeout: (int) $config->get('boot-up.process.terminal_pid_timeout', 20),
        );
    }
}
