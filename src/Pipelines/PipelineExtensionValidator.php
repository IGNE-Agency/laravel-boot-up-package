<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

/**
 * Turns the raw boot-up.pipeline.steps / .files config into a validated
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
     * @param  array<mixed>  $steps  raw boot-up.pipeline.steps
     * @param  array<mixed>  $files  raw boot-up.pipeline.files
     * @param  list<string>  $providers  known provider keys, for the optional provider filter
     */
    public function validate(array $steps, array $files, PipelineGenerator $generator, PipelinePlan $plan, array $providers): PipelineExtensions
    {
        return new PipelineExtensions(
            $this->steps($steps, $generator, $plan, $providers),
            $this->files($files, $providers),
        );
    }

    /**
     * @param  array<mixed>  $steps
     * @param  list<string>  $providers
     * @return list<PipelineStep>
     */
    private function steps(array $steps, PipelineGenerator $generator, PipelinePlan $plan, array $providers): array
    {
        $anchors = $generator->anchors($plan);
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

            $provider = $this->provider($raw, $providers, fn (string $problem) => PipelineException::step($id, $problem));

            $job = $this->string($raw, 'job') ?? throw PipelineException::step($id, 'is missing a non-empty "job".');
            $position = $this->string($raw, 'position') ?? throw PipelineException::step($id, 'is missing a "position" (before or after).');

            if (! \in_array($position, self::POSITIONS, true)) {
                throw PipelineException::step($id, "has an invalid position [{$position}]; use 'before' or 'after'.");
            }

            $run = $this->string($raw, 'run') ?? throw PipelineException::step($id, 'is missing a non-empty "run" command.');

            // Only steps that will render for this provider are anchor-checked.
            if (($provider === null || $provider === $generator->key()) && ! \in_array($job, $anchors, true)) {
                throw PipelineException::step($id, "targets unknown job [{$job}] for {$generator->key()}; available: ".implode(', ', $anchors).'.');
            }

            $result[] = new PipelineStep(
                id: $id,
                job: $job,
                position: $position,
                name: $this->string($raw, 'name') ?? $id,
                run: $run,
                provider: $provider,
                env: $this->env($raw, $id),
            );
        }

        return $result;
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

            $hasContents = \array_key_exists('contents', $raw);
            $hasStub = \array_key_exists('stub', $raw);

            if ($hasContents === $hasStub) {
                throw PipelineException::file($path, 'needs exactly one of "contents" or "stub".');
            }

            $result[] = new PipelineFile(
                path: $path,
                contents: $hasStub ? $this->stub($path, (string) $raw['stub']) : (string) $raw['contents'],
                executable: (bool) ($raw['executable'] ?? false),
                provider: $this->provider($raw, $providers, fn (string $problem) => PipelineException::file($path, $problem)),
            );
        }

        return $result;
    }

    private function stub(string $path, string $stub): string
    {
        $full = rtrim($this->basePath, '/').'/'.ltrim($stub, '/');

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
            throw $fail('targets an unknown provider; available: '.implode(', ', $providers).'.');
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
