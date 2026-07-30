<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Illuminate\Contracts\Container\Container;

/**
 * Picks the server driver for this run: explicit argument, then the
 * configured default, then an interactive select over the driver map.
 */
final class ServerSelector
{
    public function __construct(
        private readonly Container $container,
        private readonly DevServerConfig $config,
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
            return $this->driver('laravel');
        }

        return $this->driver((string) terminal()->select(
            label: 'Which development server should serve the application?',
            options: $this->options(),
        ));
    }

    public function driver(string $key): Server
    {
        $class = $this->config->drivers[$key] ?? throw ServerException::unknownServer($key);

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
