<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * The standard rewrite-then-run sequence for commands that must pass
 * through the context server's rewrites (herd php, sail artisan, ...).
 * Expects the using class to carry ProcessRunner $runner and
 * CommandRewriter $rewriter.
 */
trait RunsThroughServer
{
    private function runThroughServer(ServeContext $context, CommandLine $command): ProcessResult
    {
        return $this->runner->run($this->rewriter->rewriteFor($context, $command));
    }

    private function runSilentlyThroughServer(ServeContext $context, CommandLine $command): ProcessResult
    {
        return $this->runner->runSilently($this->rewriter->rewriteFor($context, $command));
    }
}
