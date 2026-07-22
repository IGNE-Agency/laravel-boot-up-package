<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Console\Support\Selection;
use Igne\LaravelBootUp\Pipelines\BitbucketPipelinesGenerator;
use Igne\LaravelBootUp\Pipelines\DeployHookHost;
use Igne\LaravelBootUp\Pipelines\GeneratedFile;
use Igne\LaravelBootUp\Pipelines\GitHubActionsGenerator;
use Igne\LaravelBootUp\Pipelines\PipelineConfig;
use Igne\LaravelBootUp\Pipelines\PipelineEnvFile;
use Igne\LaravelBootUp\Pipelines\PipelineExtensionValidator;
use Igne\LaravelBootUp\Pipelines\PipelineFile;
use Igne\LaravelBootUp\Pipelines\PipelineGenerator;
use Igne\LaravelBootUp\Pipelines\PipelinePlan;
use Igne\LaravelBootUp\Pipelines\PipelinePlanner;
use Igne\LaravelBootUp\Pipelines\PipelineSecret;
use Igne\LaravelBootUp\Support\AtomicFile;

final class PipelineCommand extends BootUpCommand
{
    private const BUILT_IN_GENERATORS = [
        'github' => GitHubActionsGenerator::class,
        'bitbucket' => BitbucketPipelinesGenerator::class,
    ];

    protected $signature = 'app:pipeline
        {provider? : The git provider (github, bitbucket, or any generator registered in boot-up.pipeline.generators)}
        {host? : The deploy-hook host (fortrabbit, forge, webhook), or "none" to skip the deploy step}
        {--force : Overwrite existing pipeline, scripts/ci and .env.pipeline files without asking}';

    protected $description = 'Generate a CI/CD pipeline, its shared scripts/ci files and .env.pipeline for a git provider, based on this package\'s config';

    public function perform(PipelinePlanner $planner, PipelineConfig $config, PipelineEnvFile $envFile, Selection $selection): int
    {
        terminal()->intro('Generating the CI/CD pipeline...');

        $generators = array_merge(self::BUILT_IN_GENERATORS, $config->generators);

        $provider = $this->provider($generators, $selection);

        if (! isset($generators[$provider])) {
            terminal()->error("Unknown provider [{$provider}]. Available: ".implode(', ', array_keys($generators)));

            return self::FAILURE;
        }

        /** @var PipelineGenerator $generator */
        $generator = $this->laravel->make($generators[$provider]);

        $host = $this->host($selection);

        if (\is_string($host)) {
            terminal()->error("Unknown host [{$host}]. Available: ".implode(', ', array_column(DeployHookHost::cases(), 'value')));

            return self::FAILURE;
        }

        $plan = $planner->plan($host);

        $extensions = (new PipelineExtensionValidator($this->laravel->basePath()))
            ->validate($config->steps, $config->files, $generator, $plan, array_keys($generators));

        $plan = $plan->withExtensions($extensions);

        $files = [
            ...$generator->files($plan),
            new GeneratedFile($envFile->path(), $envFile->generate()),
            ...array_map(
                fn (PipelineFile $file): GeneratedFile => new GeneratedFile($file->path, $file->contents, $file->executable),
                $extensions->filesFor($provider),
            ),
        ];

        if (! $this->confirmOverwrites($files)) {
            return self::SUCCESS;
        }

        foreach ($files as $file) {
            $this->write($file);
        }

        $this->instructions($generator, $plan);

        terminal()->outro('Pipeline generated.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, class-string<PipelineGenerator>>  $generators
     */
    private function provider(array $generators, Selection $selection): string
    {
        $options = [];

        foreach ($generators as $key => $class) {
            $options[$key] = $this->laravel->make($class)->label();
        }

        return $selection->resolve($this->argument('provider'), $options, 'Which git provider should the pipeline target?');
    }

    /**
     * The chosen deploy-hook host, or the unrecognized argument verbatim so
     * the caller can report it. For real hosts only the printed guidance
     * differs — the generated files work with any HTTPS deploy hook; picking
     * none generates a checks-only pipeline without deploy steps.
     */
    private function host(Selection $selection): DeployHookHost|string
    {
        $options = [];

        foreach (DeployHookHost::cases() as $host) {
            $options[$host->value] = $host->label();
        }

        $choice = $selection->resolve(
            $this->argument('host'),
            $options,
            'Which host receives the deploy hook?',
            DeployHookHost::FORTRABBIT->value,
        );

        return DeployHookHost::tryFrom($choice) ?? $choice;
    }

    /**
     * All-or-nothing: the pipeline file and its scripts reference each other,
     * so a partial write could leave YAML calling scripts that were never
     * updated. One prompt covers everything that would be overwritten;
     * declining writes nothing.
     *
     * @param  list<GeneratedFile>  $files
     */
    private function confirmOverwrites(array $files): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $existing = [];

        foreach ($files as $file) {
            if (is_file($this->laravel->basePath($file->path))) {
                $existing[] = $file->path;
            }
        }

        $confirmed = match (\count($existing)) {
            0 => true,
            1 => terminal()->confirm("{$existing[0]} already exists. Overwrite it?", default: false),
            default => terminal()->confirm(
                'Overwrite these '.\count($existing).' existing files? '.implode(', ', $existing),
                default: false,
            ),
        };

        if (! $confirmed) {
            terminal()->warning('Nothing written — declined to overwrite existing files.');
        }

        return $confirmed;
    }

    private function write(GeneratedFile $file): void
    {
        $path = $this->laravel->basePath($file->path);

        AtomicFile::write($path, $file->contents);

        if ($file->executable) {
            chmod($path, 0755);
        }

        terminal()->success("Wrote {$file->path}.");
    }

    private function instructions(PipelineGenerator $generator, PipelinePlan $plan): void
    {
        $secrets = $generator->secrets($plan);

        if ($secrets !== []) {
            terminal()->table(
                ['Secret', 'Add under (git provider)', 'Purpose'],
                array_map(fn (PipelineSecret $secret) => [$secret->name, $secret->location, $secret->purpose], $secrets),
            );
        }

        foreach ($secrets as $secret) {
            if ($secret->details !== []) {
                terminal()->section("{$secret->name} — {$secret->location}", $secret->details);
            }
        }

        terminal()->orderedList('Next steps', $generator->instructions($plan));
    }
}
