<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Pipelines\BitbucketPipelinesGenerator;
use Igne\LaravelBootUp\Pipelines\GitHubActionsGenerator;
use Igne\LaravelBootUp\Pipelines\PipelineConfig;
use Igne\LaravelBootUp\Pipelines\PipelineEnvFile;
use Igne\LaravelBootUp\Pipelines\PipelineGenerator;
use Igne\LaravelBootUp\Pipelines\PipelinePlan;
use Igne\LaravelBootUp\Pipelines\PipelinePlanner;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class PipelineCommand extends Command
{
    private const BUILT_IN_GENERATORS = [
        'github' => GitHubActionsGenerator::class,
        'bitbucket' => BitbucketPipelinesGenerator::class,
    ];

    protected $signature = 'app:pipeline
        {provider? : The git provider (github, bitbucket)}
        {--force : Overwrite existing pipeline and .env.pipeline files without asking}';

    protected $description = 'Generate a CI/CD pipeline and .env.pipeline for a git provider, based on this package\'s config';

    public function handle(PipelinePlanner $planner, PipelineConfig $config, PipelineEnvFile $envFile): int
    {
        $generators = array_merge(self::BUILT_IN_GENERATORS, $config->generators);

        $provider = $this->provider($generators);

        if (! isset($generators[$provider])) {
            error("Unknown provider [{$provider}]. Available: ".implode(', ', array_keys($generators)));

            return self::FAILURE;
        }

        /** @var PipelineGenerator $generator */
        $generator = $this->laravel->make($generators[$provider]);

        $plan = $planner->plan();

        $this->write($generator->path(), $generator->generate($plan));
        $this->write($envFile->path(), $envFile->generate());

        $this->instructions($generator, $plan);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, class-string<PipelineGenerator>>  $generators
     */
    private function provider(array $generators): string
    {
        $argument = $this->argument('provider');

        if (\is_string($argument) && $argument !== '') {
            return strtolower($argument);
        }

        $options = [];

        foreach ($generators as $key => $class) {
            $options[$key] = $this->laravel->make($class)->label();
        }

        return (string) select('Which git provider should the pipeline target?', $options);
    }

    private function write(string $relativePath, string $content): void
    {
        $path = $this->laravel->basePath($relativePath);

        if (is_file($path) && ! $this->option('force')
            && ! confirm("{$relativePath} already exists. Overwrite it?", default: false)) {
            warning("Skipped {$relativePath}.");

            return;
        }

        $directory = \dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, recursive: true);
        }

        file_put_contents($path, $content);
        note("Wrote {$relativePath}.");
    }

    private function instructions(PipelineGenerator $generator, PipelinePlan $plan): void
    {
        $rows = [];

        foreach ($plan->branchHooks as $branch => $hook) {
            $rows[] = [$hook, "Deploy webhook URL, requested after a green push to {$branch}"];
        }

        if ($plan->nova) {
            $rows[] = ['COMPOSER_AUTH', 'Composer auth JSON for nova.laravel.com'];
        }

        table(['Secret / variable', 'Purpose'], $rows);

        note(implode("\n", $generator->instructions($plan)));
    }
}
