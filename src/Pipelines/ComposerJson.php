<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Pipelines;

final class ComposerJson
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether the package is a production dependency (require, not require-dev).
     */
    public function requires(string $package): bool
    {
        $require = $this->read()['require'] ?? [];

        return \is_array($require) && \array_key_exists($package, $require);
    }

    /**
     * The first major.minor version in the require.php constraint, so
     * "^8.3", "8.3.*" and ">=8.3 <8.5" all resolve to "8.3".
     */
    public function phpVersion(string $default = '8.4'): string
    {
        $constraint = $this->read()['require']['php'] ?? null;

        if (\is_string($constraint) && preg_match('/(\d+)\.(\d+)/', $constraint, $matches) === 1) {
            return "{$matches[1]}.{$matches[2]}";
        }

        return $default;
    }
}
