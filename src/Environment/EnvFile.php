<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Environment;

use Igne\LaravelBootUp\Exceptions\EnvironmentException;
use Igne\LaravelBootUp\Services\AtomicFile;

/**
 * Line-preserving reader/writer for the application's .env file.
 * Writes are atomic (tmp file + rename) so a crash never leaves a
 * half-written environment behind.
 */
final class EnvFile
{
    public function __construct(
        private readonly string $path,
        private readonly string $examplePath,
    ) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function createFromExample(): void
    {
        if (! is_file($this->examplePath)) {
            throw EnvironmentException::missingExampleFile();
        }

        $this->write((string) file_get_contents($this->examplePath));
    }

    /**
     * Null means the key is absent; an empty string means the key exists
     * without a value. Surrounding quotes are stripped.
     */
    public function get(string $key): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $content = (string) file_get_contents($this->path);

        if (preg_match($this->keyPattern($key), $content, $matches) !== 1) {
            return null;
        }

        return $this->unquote(trim($matches[1]));
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * The .env value when present and non-empty, the fallback otherwise.
     * On a fresh boot the loaded configuration may predate edits to the
     * .env file, so the file itself wins.
     */
    public function valueOr(string $key, string $fallback): string
    {
        $value = (string) $this->get($key);

        return $value === '' ? $fallback : $value;
    }

    public function set(string $key, string $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * @param  array<string, string>  $pairs
     */
    public function setMany(array $pairs): void
    {
        if (! $this->exists()) {
            throw EnvironmentException::missingEnvFile();
        }

        $content = (string) file_get_contents($this->path);

        foreach ($pairs as $key => $value) {
            $content = $this->apply($content, $key, $value);
        }

        $this->write($content);
    }

    /**
     * Keys that are absent from the file OR present with an empty value.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function missing(array $keys): array
    {
        return collect($keys)
            ->filter(fn (string $key): bool => ($this->get($key) ?? '') === '')
            ->values()
            ->all();
    }

    private function apply(string $content, string $key, string $value): string
    {
        $quoted = $this->quoteIfNeeded($value);
        $line = "{$key}={$quoted}";

        if (preg_match($this->keyPattern($key), $content) === 1) {
            // Callback replacement so values containing $ or \ stay literal.
            return (string) preg_replace_callback(
                $this->keyPattern($key),
                static fn (): string => $line,
                $content,
                1,
            );
        }

        if ($content !== '' && ! str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        return "{$content}{$line}\n";
    }

    private function keyPattern(string $key): string
    {
        $quotedKey = preg_quote($key, '/');

        return "/^{$quotedKey}[ \\t]*=[ \\t]*(.*)$/m";
    }

    private function quoteIfNeeded(string $value): string
    {
        if (preg_match('/[\s#\'"]/', $value) !== 1) {
            return $value;
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return "\"{$escaped}\"";
    }

    private function unquote(string $value): string
    {
        if (\strlen($value) < 2) {
            return $value;
        }

        $first = $value[0];

        if (($first !== '"' && $first !== "'") || ! str_ends_with($value, $first)) {
            return $value;
        }

        $inner = substr($value, 1, -1);

        return $first === '"' ? str_replace(['\\"', '\\\\'], ['"', '\\'], $inner) : $inner;
    }

    private function write(string $content): void
    {
        AtomicFile::write($this->path, $content);
    }
}
