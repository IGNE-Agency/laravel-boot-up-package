<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use InvalidArgumentException;
use Igne\LaravelBootUp\Enums\DeployTaskType;

/**
 * A project-supplied command executed during deploy. Commands run as plain
 * argument lists (never through a shell), so validation rejects anything
 * that only makes sense with shell interpretation.
 */
final readonly class DeployTask
{
    private const DANGEROUS_WORDS = [
        'rm', 'del', 'sudo', 'kill', 'pkill', 'shutdown', 'reboot',
        'dd', 'mkfs', 'format', 'eval', 'exec', 'system', 'shell_exec', 'passthru',
    ];

    public function __construct(
        public DeployTaskType $type,
        public string $command,
        public ?string $description = null,
    ) {
        $this->validate();
    }

    public static function artisan(string $command, ?string $description = null): self
    {
        return new self(DeployTaskType::ARTISAN, $command, $description);
    }

    public static function composer(string $command, ?string $description = null): self
    {
        return new self(DeployTaskType::COMPOSER, $command, $description);
    }

    public static function packageManager(string $command, ?string $description = null): self
    {
        return new self(DeployTaskType::PACKAGE_MANAGER, $command, $description);
    }

    /**
     * Shell rendering parameterized by the caller's binaries, e.g. Forge
     * passes '$FORGE_PHP artisan' where a plain host passes 'php artisan'.
     */
    public function shellLine(string $artisan, string $composer, string $packageManager): string
    {
        return match ($this->type) {
            DeployTaskType::ARTISAN => "{$artisan} {$this->command}",
            DeployTaskType::COMPOSER => "{$composer} {$this->command}",
            DeployTaskType::PACKAGE_MANAGER => "{$packageManager} {$this->command}",
        };
    }

    private function validate(): void
    {
        if (trim($this->command) === '') {
            throw new InvalidArgumentException('Project command cannot be empty.');
        }

        if (preg_match('/[;&|`<>\n]/', $this->command) === 1 || str_contains($this->command, '$(')) {
            throw new InvalidArgumentException(
                "Project command '{$this->command}' contains shell metacharacters; commands run as plain argument lists and cannot chain, pipe or redirect."
            );
        }

        // Word-boundary matching: 'rm' blocks `rm -rf /` but not `confirm:users`,
        // 'exec' blocks `exec something` but not `php artisan execute-thing`.
        $blocked = implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), self::DANGEROUS_WORDS));

        if (preg_match('/(?<=^|\s)('.$blocked.')\b/i', trim($this->command), $matches) === 1) {
            throw new InvalidArgumentException(
                "Project command '{$this->command}' contains the blocked word '{$matches[1]}'."
            );
        }
    }
}
