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

    /**
     * Every lockfile name this manager is known to write. Bun moved from the
     * binary bun.lockb to a text bun.lock, and both are still in the wild.
     *
     * @return non-empty-list<string>
     */
    public function lockfiles(): array
    {
        return match ($this) {
            self::Bun => ['bun.lock', 'bun.lockb'],
            self::Yarn => ['yarn.lock'],
            self::Npm => ['package-lock.json'],
            self::Pnpm => ['pnpm-lock.yaml'],
        };
    }

    /**
     * The name this manager writes today — for messages and for the lockfile
     * a generated script installs against.
     */
    public function lockfile(): string
    {
        return $this->lockfiles()[0];
    }

    /**
     * @return list<string>
     */
    public function installCommand(): array
    {
        return [$this->value, 'install'];
    }

    /**
     * Refresh dependencies within the ranges package.json already allows.
     * Yarn 2+ has no `update`: `up -R` re-resolves every package matching
     * the pattern without touching the manifest, and '**' also matches
     * scoped names.
     *
     * @return list<string>
     */
    public function updateCommand(): array
    {
        return match ($this) {
            self::Yarn => ['yarn', 'up', '-R', '**'],
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
     * Whether Corepack provides this manager, at the version package.json's
     * "packageManager" field pins.
     */
    public function usesCorepack(): bool
    {
        return match ($this) {
            self::Yarn, self::Pnpm => true,
            self::Bun, self::Npm => false,
        };
    }

    /**
     * How a GENERATED deployment script invokes this manager. Only npm ships
     * with Node, and hosted build environments (fortrabbit) refuse
     * `npm i -g`, so nothing is installed globally: Corepack runs the
     * version package.json pins, and bun runs from npm's cache. Corepack
     * itself goes through npx because Node 25+ no longer bundles it — where
     * Node still does, npx uses that copy.
     */
    public function scriptBinary(): string
    {
        return match (true) {
            $this === self::Npm => 'npm',
            $this->usesCorepack() => "npx --yes corepack {$this->value}",
            default => "npx --yes {$this->value}",
        };
    }

    /**
     * The lockfile-strict install line for GENERATED scripts, falling back
     * to a plain install (shell `||` syntax — never executed locally by
     * this package). Yarn means Yarn 2+, whose strict flag is --immutable.
     */
    public function ciInstallLine(?string $binary = null): string
    {
        $binary ??= $this->value;

        $strictInstall = match ($this) {
            self::Npm => 'ci',
            self::Yarn => 'install --immutable',
            self::Pnpm, self::Bun => 'install --frozen-lockfile',
        };

        return "{$binary} {$strictInstall} || {$binary} install";
    }

    /**
     * The generated-script frontend block: the lockfile-strict install, then
     * the build, both without a global install. Without a pinned version
     * Corepack falls back to Yarn 1, which cannot read a Yarn 2+ lockfile —
     * the block warns instead of letting the deploy fail obscurely.
     */
    public function buildScriptLines(?string $pinnedVersion = null): Lines
    {
        $binary = $this->scriptBinary();

        return Lines::make()
            ->when($this === self::Yarn && $pinnedVersion === null, fn (Lines $script) => $script->warning(
                'package.json pins no Yarn version ("packageManager"), so Corepack runs Yarn 1 here —',
                'run `npx corepack use yarn@4` locally and commit package.json.',
            ))
            ->line($this->ciInstallLine($binary))
            ->line("{$binary} run build");
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
