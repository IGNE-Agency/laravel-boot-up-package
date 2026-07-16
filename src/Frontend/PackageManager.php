<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend;

use Igne\LaravelBootUp\Tools\Tool;

enum PackageManager: string
{
    case BUN = 'bun';
    case YARN = 'yarn';
    case NPM = 'npm';
    case PNPM = 'pnpm';

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
     *
     * @return list<string>
     */
    public function buildScriptLines(bool $ensureInstalled): array
    {
        $lines = [];

        if ($ensureInstalled && $this !== self::NPM) {
            $lines[] = "npm i -g {$this->value}";
        }

        $lines[] = $this->ciInstallLine();
        $lines[] = "{$this->value} run build";

        return $lines;
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
