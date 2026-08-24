<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;

/**
 * Capability: the server runs as one of the dev processes, in a tab beside
 * the queue worker and the asset watcher. Servers without this contract are
 * external to the run — Herd serves through its own nginx, and app:up
 * leaves it serving — so `php artisan dev` shows no [server] tab for them.
 */
interface ProvidesDevProcess
{
    /**
     * The command to run as the [server] process, or null when something is
     * already serving this project — a detached run, or a serve that outlived
     * the terminal that started it.
     */
    public function devProcess(BootContext $context): ?CommandLine;
}
