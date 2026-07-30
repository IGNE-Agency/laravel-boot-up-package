<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\Platform;

final class Browser
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Platform $platform,
    ) {}

    public function open(string $url): void
    {
        $binary = $this->platform->isMacos() ? 'open' : 'xdg-open';

        $this->runner->runSilently(CommandLine::make([$binary, $url]));
    }
}
