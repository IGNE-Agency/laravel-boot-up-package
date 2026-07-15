<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy;

use InvalidArgumentException;

/**
 * A project-supplied command executed during deploy. Commands run as plain
 * argument lists (never through a shell), so validation rejects anything
 * that only makes sense with shell interpretation.
 */
final readonly class ProjectCommand
{
    private const DANGEROUS_WORDS = [
        'rm', 'del', 'sudo', 'kill', 'pkill', 'shutdown', 'reboot',
        'dd', 'mkfs', 'format', 'eval', 'exec', 'system', 'shell_exec', 'passthru',
    ];

    public function __construct(
        public ProjectCommandType $type,
        public string $command,
        public ?string $description = null,
    ) {
        $this->validate();
    }

    public static function artisan(string $command, ?string $description = null): self
    {
        return new self(ProjectCommandType::ARTISAN, $command, $description);
    }

    public static function composer(string $command, ?string $description = null): self
    {
        return new self(ProjectCommandType::COMPOSER, $command, $description);
    }

    public static function packageManager(string $command, ?string $description = null): self
    {
        return new self(ProjectCommandType::PACKAGE_MANAGER, $command, $description);
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
