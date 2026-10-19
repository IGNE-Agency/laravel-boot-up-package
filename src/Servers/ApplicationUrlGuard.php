<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Contracts\AdvertisesUrl;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\EnvRestorePoint;
use Illuminate\Contracts\Config\Repository;

/**
 * Keeps APP_URL pointing at the server that is actually serving.
 *
 * A project that normally runs under Herd carries its .test hostname in
 * APP_URL. Boot it with Sail and that hostname keeps answering — from
 * Herd's own PHP on this machine, reading the .env this boot pointed at
 * containers — so the page 500s on a database host only Docker resolves,
 * while the working application sits on a port nothing advertises. Every
 * absolute link the application generates has the same problem.
 *
 * Session-scoped, like a moved APP_PORT: the value is true only while this
 * server runs, so it goes through the restore point and the teardown puts
 * the project's own URL back.
 */
final class ApplicationUrlGuard
{
    public function __construct(
        private readonly EnvFile $envFile,
        private readonly EnvRestorePoint $envRestore,
        private readonly DevServerConfig $config,
        private readonly Repository $laravelConfig,
    ) {}

    /**
     * Only drivers that can say which URL reaches them take part; the rest
     * keep whatever the project configured.
     */
    public function reconcile(BootContext $context): void
    {
        $server = $context->server;

        if (! $this->config->reconcileAppUrl || ! $server instanceof AdvertisesUrl) {
            return;
        }

        $current = $this->envFile->valueOr('APP_URL', '');

        if ($current !== '' && $server->serves($current)) {
            return;
        }

        $served = $server->servedUrl();

        if ($current === $served) {
            return;
        }

        terminal()->warning($current === ''
            ? "APP_URL is not set, so the application cannot tell anything where {$server->label()} serves it."
            : "APP_URL is {$current}, which does not reach {$server->label()} — the browser this boot opens, and every link the application generates, would go somewhere else.");

        $this->envRestore->around(fn () => $this->envFile->set('APP_URL', $served));

        // The loaded config predates the write, and the steps after this one
        // — the announcement, the deferred browser — read it.
        $this->laravelConfig->set('app.url', $served);

        terminal()->success("APP_URL set to {$served} for this session; the teardown puts yours back.");
    }
}
