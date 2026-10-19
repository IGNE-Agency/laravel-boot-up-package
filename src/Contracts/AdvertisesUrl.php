<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

/**
 * A server that knows which URL actually reaches it.
 *
 * Serving a project is not the same as owning every address that answers
 * for it. A project normally served by Herd keeps its .test hostname in
 * APP_URL, and that hostname goes on reaching Herd's own PHP — with the
 * .env this boot wrote for containers — while another server does the
 * serving. Advertising it then sends every generated link, and the browser
 * the boot opens, to the wrong server entirely.
 *
 * So a driver that can answer "does this URL reach me?" lets the boot
 * reconcile APP_URL the way it reconciles DB_*: corrected for the session,
 * put back at teardown.
 */
interface AdvertisesUrl
{
    /**
     * The URL this server serves the application on, built from what it
     * publishes rather than from what APP_URL claims.
     */
    public function servedUrl(): string;

    /**
     * Whether a URL, as written in .env, reaches this server.
     */
    public function serves(string $url): bool;
}
