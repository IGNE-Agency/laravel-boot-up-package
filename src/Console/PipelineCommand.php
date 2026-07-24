<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Config\PipelineConfig;
use Igne\LaravelBootUp\Contracts\PipelineGenerator;
use Igne\LaravelBootUp\Data\GeneratedFile;
use Igne\LaravelBootUp\Data\PipelineFile;
use Igne\LaravelBootUp\Data\PipelinePlan;
use Igne\LaravelBootUp\Data\PipelineSecret;
use Igne\LaravelBootUp\Enums\DeployHookHost;
use Igne\LaravelBootUp\Pipelines\BitbucketPipelinesGenerator;
use Igne\LaravelBootUp\Pipelines\GitHubActionsGenerator;
use Igne\LaravelBootUp\Pipelines\PipelineEnvFile;
use Igne\LaravelBootUp\Pipelines\PipelineExtensionValidator;
use Igne\LaravelBootUp\Pipelines\PipelinePlanner;
use Igne\LaravelBootUp\Services\GeneratedFilePublisher;

final class PipelineCommand extends BootUpCommand
{
    private const BUILT_IN_GENERATORS = [
        'github' => GitHubActionsGenerator::class,
        'bitbucket' => BitbucketPipelinesGenerator::class,
    ];

    protected $signature = 'generate:pipeline
        {provider? : The git provider (github, bitbucket, or any generator registered in boot-up.pipeline.generators)}
        {host? : The deploy-hook host (fortrabbit, forge, webhook), or "none" to skip the deploy step}
        {--force : Overwrite existing pipeline, scripts/ci and .env.pipeline files without asking}
        {--regenerate-app-key : Generate a fresh APP_KEY in .env.pipeline instead of keeping the existing one}';

    protected $description = 'Generate a CI/CD pipeline, its shared scripts/ci files and .env.pipeline for a git provider, based on this package\'s config';

    public function handle(
        PipelinePlanner $planner,
        PipelineConfig $config,
        PipelineEnvFile $envFile,
        GeneratedFilePublisher $publisher,
    ): int {
        $this->announce('Generating the CI/CD pipeline...');

        $provider = $this->choose('provider', 'Which git provider should the pipeline target?');

        /** @var PipelineGenerator $generator */
        $generator = $this->laravel->make($this->generators()[$provider]);

        $host = DeployHookHost::from(
            $this->choose('host', 'Which host receives the deploy hook?', DeployHookHost::FORTRABBIT->value),
        );

        $plan = $planner->plan($host);

        $extensions = (new PipelineExtensionValidator($this->laravel->basePath()))
            ->validate($config->steps, $config->files, $generator, $plan, array_keys($this->generators()));

        $plan = $plan->withExtensions($extensions);

        $files = [
            ...$generator->files($plan),
            new GeneratedFile($envFile->path(), $envFile->generate($this->appKey($envFile))),
            ...array_map(
                fn (PipelineFile $file): GeneratedFile => new GeneratedFile($file->path, $file->contents, $file->executable),
                $extensions->filesFor($provider),
            ),
        ];

        if (! $publisher->publish($files, (bool) $this->option('force'))) {
            return self::SUCCESS;
        }

        $this->instructions($generator, $plan);

        return $this->done('Pipeline generated.');
    }

    /**
     * @return array<string, class-string<PipelineGenerator>>
     */
    private function generators(): array
    {
        return array_merge(self::BUILT_IN_GENERATORS, $this->laravel->make(PipelineConfig::class)->generators);
    }

    /**
     * @return array<string, string>
     */
    protected function providerOptions(): array
    {
        return collect($this->generators())
            ->map(fn (string $class): string => $this->laravel->make($class)->label())
            ->all();
    }

    /**
     * For real hosts only the printed guidance differs — the generated
     * files work with any HTTPS deploy hook; picking none generates a
     * checks-only pipeline without deploy steps.
     *
     * @return array<string, string>
     */
    protected function hostOptions(): array
    {
        return collect(DeployHookHost::cases())
            ->mapWithKeys(fn (DeployHookHost $host): array => [$host->value => $host->label()])
            ->all();
    }

    /**
     * The APP_KEY to write into .env.pipeline: the one already committed
     * there is kept so regeneration doesn't churn the file in git, unless
     * --regenerate-app-key is given or no usable key exists yet (null lets
     * PipelineEnvFile mint a fresh one).
     */
    private function appKey(PipelineEnvFile $envFile): ?string
    {
        if ($this->option('regenerate-app-key')) {
            return null;
        }

        $path = $this->laravel->basePath($envFile->path());

        if (! is_file($path)) {
            return null;
        }

        return $envFile->currentAppKey((string) file_get_contents($path));
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

        $notes = $generator->notes($plan);

        if ($notes !== []) {
            terminal()->summary('Good to know', $notes);
        }

        terminal()->orderedList('Next steps', $generator->instructions($plan));
    }
}
