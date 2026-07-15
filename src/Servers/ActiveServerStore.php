<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

/**
 * Persists the active-server record across the app:serve / app:down
 * boundary as atomic JSON in storage/framework/boot-up.
 */
final class ActiveServerStore
{
    public function __construct(private readonly string $path) {}

    public function remember(ActiveServer $server): void
    {
        $directory = \dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $temporary = $this->path.'.tmp';
        file_put_contents($temporary, json_encode($server->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        rename($temporary, $this->path);
    }

    public function current(): ?ActiveServer
    {
        if (! is_file($this->path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (! \is_array($decoded) || ! isset($decoded['key'], $decoded['started_by_us'], $decoded['serve_pid'], $decoded['started_at'])) {
            return null;
        }

        return ActiveServer::fromArray($decoded);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
