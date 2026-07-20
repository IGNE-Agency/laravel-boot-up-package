<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Pipelines\BitbucketPipelinesGenerator;
use Igne\LaravelBootUp\Pipelines\DeployHookHost;
use Igne\LaravelBootUp\Pipelines\GeneratedFile;
use Igne\LaravelBootUp\Pipelines\GitHubActionsGenerator;
use Igne\LaravelBootUp\Pipelines\PipelineConfig;
use Igne\LaravelBootUp\Pipelines\PipelineEnvFile;
use Igne\LaravelBootUp\Pipelines\PipelineGenerator;
use Igne\LaravelBootUp\Pipelines\PipelinePlan;
use Igne\LaravelBootUp\Pipelines\PipelinePlanner;
use Igne\LaravelBootUp\Pipelines\PipelineSecret;
use Igne\LaravelBootUp\Support\AtomicFile;
use Igne\LaravelBootUp\Support\Lines;
use Illuminate\Console\Command;
use Laravel\Prompts\Concerns\Colors;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class PipelineCommand extends Command
{
    use Colors;

    private const BUILT_IN_GENERATORS = [
        'github' => GitHubActionsGenerator::class,
        'bitbucket' => BitbucketPipelinesGenerator::class,
    ];

    protected $signature = 'app:pipeline
        {provider? : The git provider (github, bitbucket)}
        {host? : The deploy-hook host (fortrabbit, forge, webhook)}
        {--force : Overwrite existing pipeline, scripts/ci and .env.pipeline files without asking}';

    protected $description = 'Generate a CI/CD pipeline, its shared scripts/ci files and .env.pipeline for a git provider, based on this package\'s config';

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

        $host = $this->host();

        if (\is_string($host)) {
            error("Unknown host [{$host}]. Available: ".implode(', ', array_column(DeployHookHost::cases(), 'value')));

            return self::FAILURE;
        }

        $plan = $planner->plan($host);

        $files = [
            ...$generator->files($plan),
            new GeneratedFile($envFile->path(), $envFile->generate()),
        ];

        if (! $this->confirmOverwrites($files)) {
            return self::SUCCESS;
        }

        foreach ($files as $file) {
            $this->write($file);
        }

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

    /**
     * The chosen deploy-hook host, or the unrecognized argument verbatim so
     * the caller can report it. Only the printed guidance depends on this —
     * the generated files work with any HTTPS deploy hook.
     */
    private function host(): DeployHookHost|string
    {
        $argument = $this->argument('host');

        if (\is_string($argument) && $argument !== '') {
            return DeployHookHost::tryFrom(strtolower($argument)) ?? strtolower($argument);
        }

        $options = [];

        foreach (DeployHookHost::cases() as $host) {
            $options[$host->value] = $host->label();
        }

        return DeployHookHost::from((string) select(
            label: 'Which host receives the deploy hook?',
            options: $options,
            default: DeployHookHost::FORTRABBIT->value,
        ));
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
            1 => confirm("{$existing[0]} already exists. Overwrite it?", default: false),
            default => confirm(
                'Overwrite these '.\count($existing).' existing files? '.implode(', ', $existing),
                default: false,
            ),
        };

        if (! $confirmed) {
            warning('Nothing written — declined to overwrite existing files.');
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

        note("Wrote {$file->path}.");
    }

    private function instructions(PipelineGenerator $generator, PipelinePlan $plan): void
    {
        $secrets = $generator->secrets($plan);

        if ($secrets !== []) {
            table(
                ['Secret', 'Add under', 'Purpose'],
                array_map(fn (PipelineSecret $secret) => [$secret->name, $secret->location, $secret->purpose], $secrets),
            );
        }

        foreach ($secrets as $secret) {
            $this->section($this->cyan("{$secret->name} — {$secret->location}"), $secret->details);
        }

        $this->section($this->green('Next steps'), $generator->instructions($plan));
    }

    /**
     * A spaced note block: colored heading, body indented beneath it.
     *
     * @param  list<string>  $lines
     */
    private function section(string $heading, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $this->newLine();

        note(implode("\n", Lines::make()
            ->line($heading)
            ->indent(2, fn (Lines $note) => $note->lines($lines))
            ->toArray()));
    }
}
