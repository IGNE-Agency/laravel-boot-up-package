<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

use Igne\LaravelBootUp\Concerns\ResolvesFromConfig;
use Igne\LaravelBootUp\Data\Lines;

enum PackageManager: string
{
    use ResolvesFromConfig;

    case BUN = 'bun';
    case YARN = 'yarn';
    case NPM = 'npm';
    case PNPM = 'pnpm';

    public static function default(): self
    {
        return self::BUN;
    }

    public function binary(): string
    {
        return $this->value;
    }

    public function lockfile(): string
    {
        return match ($this) {
            self::BUN => 'bun.lock',
            self::YARN => 'yarn.lock',
            self::NPM => 'package-lock.json',
            self::PNPM => 'pnpm-lock.yaml',
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
            self::NPM => ['npm', 'update'],
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
     * The lockfile-strict install line for GENERATED deployment scripts,
     * falling back to a plain install (shell `||` syntax — never executed
     * locally by this package).
     */
    public function ciInstallLine(): string
    {
        return match ($this) {
            self::NPM => 'npm ci || npm install',
            self::PNPM => 'pnpm install --frozen-lockfile || pnpm install',
            self::YARN => 'yarn install --frozen-lockfile || yarn install',
            self::BUN => 'bun install --frozen-lockfile || bun install',
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
            ->lineIf($ensureInstalled && $this !== self::NPM, "npm i -g {$this->value}")
            ->line($this->ciInstallLine())
            ->line("{$this->value} run build");
    }

    public function tool(): Tool
    {
        return match ($this) {
            self::BUN => Tool::BUN,
            self::YARN => Tool::YARN,
            self::NPM => Tool::NPM,
            self::PNPM => Tool::PNPM,
        };
    }
}
