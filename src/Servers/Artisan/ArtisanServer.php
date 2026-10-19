<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Artisan;

use Igne\LaravelBootUp\Concerns\ServesHttpOnPort;
use Igne\LaravelBootUp\Config\ArtisanServeConfig;
use Igne\LaravelBootUp\Contracts\AdvertisesUrl;
use Igne\LaravelBootUp\Contracts\ProvidesDevProcess;
use Igne\LaravelBootUp\Contracts\ReservesPorts;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ReservedPort;
use Igne\LaravelBootUp\Process\ProcessReaper;

/**
 * Serves through `php artisan serve`.
 *
 * The serve command is a dev process, not something the setup starts: the
 * multiplexer owns its output and restarts it if it dies, and a detached run
 * starts it from the registry like any other. So there is nothing to do here
 * beyond saying which command runs it.
 */
final class ArtisanServer implements AdvertisesUrl, ProvidesDevProcess, ReservesPorts, Server
{
    use ServesHttpOnPort;

    private const string LABEL = 'artisan-serve';

    /** Bind addresses that mean "every interface" rather than an address to visit. */
    private const array WILDCARD_HOSTS = ['0.0.0.0', '::', '[::]'];

    public function __construct(
        private readonly ProcessReaper $reaper,
        private readonly ArtisanServeConfig $config,
    ) {}

    public function key(): string
    {
        return 'artisan';
    }

    public function label(): string
    {
        return 'Laravel (php artisan serve)';
    }

    public function start(BootContext $context): void
    {
        terminal()->note($this->isRunning()
            ? 'php artisan serve is already running.'
            : 'php artisan serve runs with the dev processes.');
    }

    /**
     * The serve command binds this itself, and fails with its own terse
     * "Failed to listen" if something else has it. Not remappable: the port
     * is configuration, not a forward this package may quietly rewrite.
     *
     * @return list<ReservedPort>
     */
    public function reservedPorts(): array
    {
        return [new ReservedPort(
            port: $this->config->port,
            purpose: 'php artisan serve',
            fix: 'change boot-up.artisan.port (or BOOT_UP_ARTISAN_PORT)',
        )];
    }

    /**
     * Runs as the [server] process, exactly as it does under plain
     * `php artisan dev`. Null only when a tracked serve is already alive --
     * one a detached run started, or one that outlived its terminal.
     */
    public function devProcess(BootContext $context): ?CommandLine
    {
        return $this->isRunning() ? null : $this->serveCommand();
    }

    private function serveCommand(): CommandLine
    {
        return CommandLine::make([
            'php', 'artisan', 'serve',
            "--host={$this->config->host}",
            "--port={$this->config->port}",
        ])->withTimeout(null);
    }

    public function isRunning(): bool
    {
        return $this->reaper->isRunning(self::LABEL);
    }

    public function stop(): void
    {
        $this->reaper->stop(self::LABEL);
    }

    /**
     * Derived from the configured bind address — `php artisan serve` never
     * honors APP_URL, so consulting app.url would announce a URL the
     * server does not actually listen on.
     */
    public function url(): string
    {
        return "http://{$this->config->host}:{$this->config->port}";
    }

    /**
     * What the application should advertise. Almost url(), except for a
     * wildcard bind: `0.0.0.0` is an instruction to listen on everything,
     * not an address a browser or a generated link can go to, so the
     * loopback name it also answers on stands in for it.
     */
    public function servedUrl(): string
    {
        $host = \in_array($this->config->host, self::WILDCARD_HOSTS, true)
            ? 'localhost'
            : $this->config->host;

        return $this->httpUrlOn($host, $this->config->port);
    }

    /**
     * `serve` speaks plain HTTP on the one port it binds, and binds it on
     * the loopback by default — so the hostname stays the project's own
     * business and only the port and the scheme are checked.
     */
    public function serves(string $url): bool
    {
        return $this->urlReachesPort($url, $this->config->port);
    }
}
