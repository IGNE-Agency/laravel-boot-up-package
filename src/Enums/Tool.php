<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

enum Tool: string
{
    case Homebrew = 'homebrew';
    case Php = 'php';
    case Node = 'node';
    case Composer = 'composer';
    case Docker = 'docker';
    case Herd = 'herd';
    case Bun = 'bun';
    case Yarn = 'yarn';
    case Npm = 'npm';
    case Pnpm = 'pnpm';

    public function binary(): string
    {
        return match ($this) {
            self::Homebrew => 'brew',
            default => $this->value,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Homebrew => 'Homebrew',
            self::Php => 'PHP',
            self::Node => 'Node.js',
            self::Composer => 'Composer',
            self::Docker => 'Docker',
            self::Herd => 'Laravel Herd',
            self::Bun => 'Bun',
            self::Yarn => 'Yarn',
            self::Npm => 'npm',
            self::Pnpm => 'pnpm',
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
            self::Php => ['php', '-r', 'echo PHP_VERSION;'],
            self::Homebrew => ['brew', '--version'],
            self::Composer => ['composer', '--version'],
            self::Docker => ['docker', '--version'],
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
            self::Docker, self::Herd => true,
            default => false,
        };
    }
}
