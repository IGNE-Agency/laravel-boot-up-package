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
     * Remove these keys entirely. A key that is not there is not an error —
     * the caller wants it gone, and it is.
     *
     * @param  list<string>  $keys
     */
    public function remove(array $keys): void
    {
        if ($keys === [] || ! $this->exists()) {
            return;
        }

        $content = (string) file_get_contents($this->path);

        foreach ($keys as $key) {
            // The line's own newline goes with it, so removing a key does not
            // leave a blank line where it was.
            $content = (string) preg_replace($this->keyPattern($key, wholeLine: true), '', $content, 1);
        }

        $this->write($content);
    }

    /**
     * Every key in the file with its value, for callers that have to compare
     * the whole environment before and after something else wrote to it.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if (! $this->exists()) {
            return [];
        }

        preg_match_all(
            '/'.self::linePattern('([A-Za-z_][A-Za-z0-9_.]*)').'/m',
            (string) file_get_contents($this->path),
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [$match[1] => $this->unquote(trim($match[2]))])
            ->all();
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

    private function keyPattern(string $key, bool $wholeLine = false): string
    {
        $line = self::linePattern(preg_quote($key, '/'));

        return $wholeLine ? "/{$line}\\R?/m" : "/{$line}/m";
    }

    /**
     * The one definition of "a KEY= line", shared by the single-key pattern
     * and all()'s scan so get/set/remove and all() agree by construction —
     * an indented key that only all() could see would be recorded as changed
     * by EnvRestorePoint and then never restored, and setMany() on it would
     * append a duplicate line instead of editing it.
     *
     * $key arrives regex-ready: a preg_quote()d literal or a capturing class.
     */
    private static function linePattern(string $key): string
    {
        return "^[ \\t]*{$key}[ \\t]*=[ \\t]*(.*)$";
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
