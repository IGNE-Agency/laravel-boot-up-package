<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Serve;

use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;

final class Browser
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function open(string $url): void
    {
        $binary = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            default => 'xdg-open',
        };

        $this->runner->runSilently(ShellCommand::make([$binary, $url]));
    }
}
