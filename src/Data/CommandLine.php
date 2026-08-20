<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

/**
 * Immutable description of a shell command. Prefer the array form for
 * arguments containing spaces; the string form splits on whitespace.
 */
final readonly class CommandLine
{
    /**
     * The ceiling for an ordinary command. Not configurable: the constructor
     * is private and make() takes no timeout, so "no timeout was given" and
     * "300 was given" are the same state -- a knob here would need a sentinel.
     * Long-running work calls withTimeout() explicitly.
     */
    public const int DEFAULT_TIMEOUT_SECONDS = 300;

    /**
     * @param  list<string>  $tokens
     * @param  array<string, string>  $env
     */
    private function __construct(
        public array $tokens,
        public ?string $cwd = null,
        public array $env = [],
        public ?int $timeout = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    /**
     * @param  string|list<string>  $command
     */
    public static function make(string|array $command): self
    {
        return new self(self::tokenize($command));
    }

    /**
     * Append CLI options: integer keys become bare flags, boolean true renders
     * the key alone, false/null entries are dropped, values render as key=value.
     *
     * @param  array<int|string, mixed>  $options
     */
    public function withOptions(array $options): self
    {
        $rendered = [];

        foreach ($options as $key => $value) {
            if (\is_int($key)) {
                [$key, $value] = [$value, true];
            }

            if ($value === false || $value === null) {
                continue;
            }

            $rendered[] = $value === true ? $key : "{$key}={$value}";
        }

        return $this->withTokens([...$this->tokens, ...$rendered]);
    }

    /**
     * @param  list<string>  $tokens
     */
    public function withTokens(array $tokens): self
    {
        return new self($tokens, $this->cwd, $this->env, $this->timeout);
    }

    public function inDirectory(string $cwd): self
    {
        return new self($this->tokens, $cwd, $this->env, $this->timeout);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function withEnv(array $env): self
    {
        return new self($this->tokens, $this->cwd, [...$this->env, ...$env], $this->timeout);
    }

    /**
     * Null disables the timeout entirely (long-running watchers).
     */
    public function withTimeout(?int $seconds): self
    {
        return new self($this->tokens, $this->cwd, $this->env, $seconds);
    }

    public function toString(): string
    {
        return implode(' ', array_map(self::escapeToken(...), $this->tokens));
    }

    /**
     * Array elements are kept verbatim (argv semantics); a string splits
     * on whitespace.
     *
     * @param  string|list<string>  $command
     * @return list<string>
     */
    private static function tokenize(string|array $command): array
    {
        if (\is_array($command)) {
            return array_values(array_filter($command, static fn (string $token): bool => $token !== ''));
        }

        return array_values(array_filter(
            preg_split('/\s+/', trim($command)) ?: [],
            static fn (string $token): bool => $token !== '',
        ));
    }

    private static function escapeToken(string $token): string
    {
        return preg_match('#^[\w@%+=:,./-]+$#', $token) === 1 ? $token : escapeshellarg($token);
    }
}
