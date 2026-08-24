<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Concerns\ValidatesConfig;
use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Picks the server driver for this run: explicit argument, then the
 * configured default, then an interactive select over the driver map.
 */
final class ServerSelector
{
    use ValidatesConfig;

    public function __construct(
        private readonly Container $container,
        private readonly DevServerConfig $config,
        private readonly ActiveServerStore $store,
    ) {}

    public function select(?string $argument): Server
    {
        if ($argument !== null) {
            return $this->driver(strtolower($argument));
        }

        if ($this->config->default !== null) {
            return $this->driver($this->config->default);
        }

        if (! $this->config->prompt) {
            return $this->driver('artisan');
        }

        return $this->driver((string) terminal()->select(
            label: 'Which development server should serve the application?',
            options: $this->options(),
        ));
    }

    /**
     * The server this project is already set up with, resolved without ever
     * prompting: an explicit argument, then the record app:up persisted,
     * then the configured default.
     *
     * Null means nothing on this machine says which server serves this
     * project. The caller reports that rather than guessing — falling back to
     * `artisan` would bind a port for a project Herd already serves.
     */
    public function remembered(?string $argument): ?Server
    {
        if ($argument !== null) {
            return $this->driver(strtolower($argument));
        }

        $key = $this->store->current()?->key;

        if ($key !== null) {
            try {
                return $this->driver($key);
            } catch (Throwable) {
                terminal()->warning("The recorded server [{$key}] is not a known driver — run php artisan app:up to choose one.");
            }
        }

        return $this->config->default === null ? null : $this->driver($this->config->default);
    }

    public function driver(string $key): Server
    {
        $class = $this->config->drivers[$key] ?? throw ServerException::unknownServer($key);

        // Both select() and options() come through here, so one check covers
        // the argument, the configured default and the interactive picker.
        self::validatedClass($class, "boot-up.server.drivers.{$key}", Server::class);

        return $this->container->make($class);
    }

    /**
     * @return array<string, string>
     */
    private function options(): array
    {
        $options = [];

        foreach (array_keys($this->config->drivers) as $key) {
            $options[$key] = $this->driver($key)->label();
        }

        return $options;
    }
}
