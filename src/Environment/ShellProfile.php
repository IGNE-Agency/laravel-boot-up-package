<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Environment;

/**
 * The user's shell profile (~/.zshrc or ~/.bashrc), used to install
 * package-managed additions such as the sail alias under marker lines.
 */
final class ShellProfile
{
    private const string BLOCK_START = '# >>> laravel-bootstrap >>>';

    private const string BLOCK_END = '# <<< laravel-bootstrap <<<';

    public function __construct(
        private readonly ?string $home = null,
        private readonly ?string $shell = null,
    ) {}

    public function path(): ?string
    {
        $home = $this->home ?? (getenv('HOME') ?: null);
        $shell = $this->shell ?? (getenv('SHELL') ?: null);

        if ($home === null || $shell === null) {
            return null;
        }

        return match (basename($shell)) {
            'zsh' => $home.'/.zshrc',
            'bash' => $home.'/.bashrc',
            default => null,
        };
    }

    public function exists(): bool
    {
        $path = $this->path();

        return $path !== null && is_file($path);
    }

    public function contains(string $needle): bool
    {
        return $this->exists()
            && str_contains((string) file_get_contents((string) $this->path()), $needle);
    }

    public function definesAlias(string $name): bool
    {
        if (! $this->exists()) {
            return false;
        }

        $pattern = '/^[ \t]*alias[ \t]+'.preg_quote($name, '/').'=/m';

        return preg_match($pattern, (string) file_get_contents((string) $this->path())) === 1;
    }

    /**
     * Append content wrapped in marker lines, creating the profile when it
     * does not exist yet. A no-op when the shell is unsupported.
     */
    public function appendBlock(string $content): void
    {
        $path = $this->path();

        if ($path === null) {
            return;
        }

        $existing = is_file($path) ? (string) file_get_contents($path) : '';

        if ($existing !== '' && ! str_ends_with($existing, "\n")) {
            $existing .= "\n";
        }

        $block = self::BLOCK_START."\n".rtrim($content, "\n")."\n".self::BLOCK_END."\n";

        file_put_contents($path, $existing.($existing === '' ? '' : "\n").$block);
    }
}
