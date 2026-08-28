<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

use Igne\LaravelBootUp\Data\PipelineContext;
use Igne\LaravelBootUp\Data\PipelineFile;
use Igne\LaravelBootUp\Data\PipelineJobStep;
use Igne\LaravelBootUp\Exceptions\PipelineException;

/**
 * Turns the raw step/file definitions from the pipeline config
 * (PipelineConfig) into a validated
 * PipelineExtensions, or fails with an actionable PipelineException. Anchor
 * checks run against the chosen generator's jobs for the current plan, so a
 * step targeting a job that will not exist (e.g. "lint" without Pint) is
 * rejected before anything is written.
 */
final class PipelineExtensionValidator
{
    private const array POSITIONS = ['before', 'after'];

    public function __construct(private readonly string $basePath) {}

    /**
     * @param  array<mixed>  $steps  raw step definitions from PipelineConfig
     * @param  array<mixed>  $files  raw file definitions from PipelineConfig
     */
    public function validate(array $steps, array $files, PipelineContext $context): PipelineExtensions
    {
        return new PipelineExtensions(
            $this->steps($steps, $context),
            $this->files($files, $context->providers),
        );
    }

    /**
     * @param  array<mixed>  $steps
     * @return list<PipelineJobStep>
     */
    private function steps(array $steps, PipelineContext $context): array
    {
        $seen = [];
        $result = [];

        foreach (array_values($steps) as $index => $raw) {
            if (! \is_array($raw)) {
                throw PipelineException::step("#{$index}", 'each step must be an array of settings.');
            }

            $id = $this->string($raw, 'id') ?? throw PipelineException::step("#{$index}", 'is missing a non-empty "id".');

            if (isset($seen[$id])) {
                throw PipelineException::duplicateStepId($id);
            }

            $seen[$id] = true;
            $result[] = $this->parseStep($id, $raw, $context);
        }

        return $result;
    }

    /**
     * @param  array<mixed>  $raw
     */
    private function parseStep(string $id, array $raw, PipelineContext $context): PipelineJobStep
    {
        $provider = $this->provider($raw, $context->providers, fn (string $problem) => PipelineException::step($id, $problem));

        $job = $this->string($raw, 'job') ?? throw PipelineException::step($id, 'is missing a non-empty "job".');
        $position = $this->string($raw, 'position') ?? throw PipelineException::step($id, 'is missing a "position" (before or after).');

        if (! \in_array($position, self::POSITIONS, true)) {
            throw PipelineException::step($id, "has an invalid position [{$position}]; use 'before' or 'after'.");
        }

        $run = $this->string($raw, 'run') ?? throw PipelineException::step($id, 'is missing a non-empty "run" command.');

        $anchors = $context->generator->anchors($context->plan);

        // Only steps that will render for this provider are anchor-checked.
        if (($provider === null || $provider === $context->generator->key()) && ! \in_array($job, $anchors, true)) {
            $available = implode(', ', $anchors);

            throw PipelineException::step($id, "targets unknown job [{$job}] for {$context->generator->key()}; available: {$available}.");
        }

        return new PipelineJobStep(
            id: $id,
            job: $job,
            position: $position,
            name: $this->string($raw, 'name') ?? $id,
            run: $run,
            provider: $provider,
            env: $this->env($raw, $id),
        );
    }

    /**
     * @param  array<mixed>  $files
     * @param  list<string>  $providers
     * @return list<PipelineFile>
     */
    private function files(array $files, array $providers): array
    {
        $seen = [];
        $result = [];

        foreach (array_values($files) as $index => $raw) {
            if (! \is_array($raw)) {
                throw PipelineException::file("#{$index}", 'each file must be an array of settings.');
            }

            $path = $this->string($raw, 'path') ?? throw PipelineException::file("#{$index}", 'is missing a non-empty "path".');

            if (str_starts_with($path, '/') || str_contains($path, '..')) {
                throw PipelineException::file($path, 'must be a relative path inside the repository (no leading "/" or "..").');
            }

            if (isset($seen[$path])) {
                throw PipelineException::file($path, 'is declared more than once.');
            }

            $seen[$path] = true;
            $result[] = $this->parseFile($path, $raw, $providers);
        }

        return $result;
    }

    /**
     * @param  array<mixed>  $raw
     * @param  list<string>  $providers
     */
    private function parseFile(string $path, array $raw, array $providers): PipelineFile
    {
        $hasContents = \array_key_exists('contents', $raw);
        $hasStub = \array_key_exists('stub', $raw);

        if ($hasContents === $hasStub) {
            throw PipelineException::file($path, 'needs exactly one of "contents" or "stub".');
        }

        return new PipelineFile(
            path: $path,
            contents: $hasStub ? $this->stub($path, (string) $raw['stub']) : (string) $raw['contents'],
            executable: (bool) ($raw['executable'] ?? false),
            provider: $this->provider($raw, $providers, fn (string $problem) => PipelineException::file($path, $problem)),
        );
    }

    private function stub(string $path, string $stub): string
    {
        $base = rtrim($this->basePath, '/');
        $relative = ltrim($stub, '/');
        $full = "{$base}/{$relative}";

        if (! is_file($full)) {
            throw PipelineException::file($path, "references a stub that does not exist: {$stub}.");
        }

        $contents = file_get_contents($full);

        if ($contents === false) {
            throw PipelineException::file($path, "could not read the stub: {$stub}.");
        }

        return $contents;
    }

    /**
     * @param  array<mixed>  $raw
     */
    private function string(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return \is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param  array<mixed>  $raw
     * @param  list<string>  $providers
     * @param  callable(string): PipelineException  $fail
     */
    private function provider(array $raw, array $providers, callable $fail): ?string
    {
        $provider = $raw['provider'] ?? null;

        if ($provider === null) {
            return null;
        }

        if (! \is_string($provider) || ! \in_array($provider, $providers, true)) {
            $available = implode(', ', $providers);

            throw $fail("targets an unknown provider; available: {$available}.");
        }

        return $provider;
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, string>
     */
    private function env(array $raw, string $id): array
    {
        $env = $raw['env'] ?? [];

        if (! \is_array($env)) {
            throw PipelineException::step($id, 'has an "env" that must be a map of name => value.');
        }

        $result = [];

        foreach ($env as $key => $value) {
            if (! \is_string($key) || ! is_scalar($value)) {
                throw PipelineException::step($id, 'has an "env" that must map string names to scalar values.');
            }

            $result[$key] = (string) $value;
        }

        return $result;
    }
}
