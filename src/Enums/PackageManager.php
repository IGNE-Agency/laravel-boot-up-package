<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

use Igne\LaravelBootUp\Concerns\ResolvesFromConfig;
use Igne\LaravelBootUp\Data\Lines;

enum PackageManager: string
{
    use ResolvesFromConfig;

    case Bun = 'bun';
    case Yarn = 'yarn';
    case Npm = 'npm';
    case Pnpm = 'pnpm';

    public static function default(): self
    {
        return self::Bun;
    }

    public function binary(): string
    {
        return $this->value;
    }

    public function lockfile(): string
    {
        return match ($this) {
            self::Bun => 'bun.lock',
            self::Yarn => 'yarn.lock',
            self::Npm => 'package-lock.json',
            self::Pnpm => 'pnpm-lock.yaml',
        };
    }

    /**
     * @return list<string>
     */
    public function installCommand(): array
    {
        return [$this->value, 'install'];
    }

    /**
     * @return list<string>
     */
    public function updateCommand(): array
    {
        return match ($this) {
            self::Npm => ['npm', 'update'],
            default => [$this->value, 'update'],
        };
    }

    /**
     * @return list<string>
     */
    public function runCommand(string $script): array
    {
        return [$this->value, 'run', $script];
    }

    /**
     * The runner for executing a package's binary (npx and friends), as a
     * command prefix.
     *
     * @return list<string>
     */
    public function execCommand(): array
    {
        return match ($this) {
            self::Bun => ['bunx'],
            self::Npm => ['npx'],
            self::Pnpm => ['pnpm', 'exec'],
            self::Yarn => ['yarn', 'exec'],
        };
    }

    /**
     * The lockfile-strict install line for GENERATED deployment scripts,
     * falling back to a plain install (shell `||` syntax — never executed
     * locally by this package).
     */
    public function ciInstallLine(): string
    {
        return match ($this) {
            self::Npm => 'npm ci || npm install',
            self::Pnpm => 'pnpm install --frozen-lockfile || pnpm install',
            self::Yarn => 'yarn install --frozen-lockfile || yarn install',
            self::Bun => 'bun install --frozen-lockfile || bun install',
        };
    }

    /**
     * The generated-script frontend block: optionally a global install of
     * this manager (npm is the only one preinstalled in every build
     * environment), then the lockfile-strict install, then the build.
     */
    public function buildScriptLines(bool $ensureInstalled): Lines
    {
        return Lines::make()
            ->lineIf($ensureInstalled && $this !== self::Npm, "npm i -g {$this->value}")
            ->line($this->ciInstallLine())
            ->line("{$this->value} run build");
    }

    public function tool(): Tool
    {
        return match ($this) {
            self::Bun => Tool::Bun,
            self::Yarn => Tool::Yarn,
            self::Npm => Tool::Npm,
            self::Pnpm => Tool::Pnpm,
        };
    }
}
