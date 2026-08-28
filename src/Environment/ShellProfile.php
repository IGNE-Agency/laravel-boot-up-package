<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Environment;

/**
 * The user's shell profile (~/.zshrc or ~/.bashrc), used to install
 * package-managed additions such as the sail alias under marker lines.
 */
final class ShellProfile
{
    private const string BLOCK_START = '# >>> laravel-boot-up >>>';

    private const string BLOCK_END = '# <<< laravel-boot-up <<<';

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
            'zsh' => "{$home}/.zshrc",
            'bash' => "{$home}/.bashrc",
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
        return str_contains($this->contents(), $needle);
    }

    public function definesAlias(string $name): bool
    {
        $quotedName = preg_quote($name, '/');

        return preg_match("/^[ \\t]*alias[ \\t]+{$quotedName}=/m", $this->contents()) === 1;
    }

    /**
     * The profile's content, '' when there is no profile to read.
     */
    private function contents(): string
    {
        $path = $this->path();

        return $path !== null && is_file($path) ? (string) file_get_contents($path) : '';
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
