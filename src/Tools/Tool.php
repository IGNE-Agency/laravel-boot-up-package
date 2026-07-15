<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools;

enum Tool: string
{
    case HOMEBREW = 'homebrew';
    case PHP = 'php';
    case NODE = 'node';
    case COMPOSER = 'composer';
    case DOCKER = 'docker';
    case HERD = 'herd';
    case BUN = 'bun';
    case YARN = 'yarn';
    case NPM = 'npm';
    case PNPM = 'pnpm';

    public function binary(): string
    {
        return match ($this) {
            self::HOMEBREW => 'brew',
            default => $this->value,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::HOMEBREW => 'Homebrew',
            self::PHP => 'PHP',
            self::NODE => 'Node.js',
            self::COMPOSER => 'Composer',
            self::DOCKER => 'Docker',
            self::HERD => 'Laravel Herd',
            self::BUN => 'Bun',
            self::YARN => 'Yarn',
            self::NPM => 'npm',
            self::PNPM => 'pnpm',
        };
    }

    /**
     * The command whose output reveals the installed version.
     *
     * @return list<string>
     */
    public function versionCommand(): array
    {
        return match ($this) {
            self::PHP => ['php', '-r', 'echo PHP_VERSION;'],
            self::HOMEBREW => ['brew', '--version'],
            self::COMPOSER => ['composer', '--version'],
            self::DOCKER => ['docker', '--version'],
            default => [$this->binary(), '--version'],
        };
    }

    public function parseVersion(string $output): ?string
    {
        return preg_match('/\d+\.\d+(?:\.\d+)?/', $output, $matches) === 1
            ? $matches[0]
            : null;
    }

    /**
     * Tools that keep themselves up to date (GUI apps with their own updaters)
     * are skipped by the auto-update path.
     */
    public function updatesAutomatically(): bool
    {
        return match ($this) {
            self::DOCKER, self::HERD => true,
            default => false,
        };
    }
}
