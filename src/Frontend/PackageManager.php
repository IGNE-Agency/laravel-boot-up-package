<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Frontend;

use Igne\LaravelBootstrap\Tools\Tool;

enum PackageManager: string
{
    case BUN = 'bun';
    case YARN = 'yarn';
    case NPM = 'npm';

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

    public function tool(): Tool
    {
        return match ($this) {
            self::BUN => Tool::BUN,
            self::YARN => Tool::YARN,
            self::NPM => Tool::NPM,
        };
    }
}
