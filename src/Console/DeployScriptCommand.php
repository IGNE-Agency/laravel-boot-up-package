<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Concerns\ResolvesGenerators;
use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Contracts\ScriptGenerator;
use Igne\LaravelBootUp\Contracts\WritesDeployFile;
use Igne\LaravelBootUp\Data\GeneratedFile;
use Igne\LaravelBootUp\Data\Lines;
use Igne\LaravelBootUp\Deploy\Scripts\DeploymentPlanner;
use Igne\LaravelBootUp\Deploy\Scripts\ForgeScriptGenerator;
use Igne\LaravelBootUp\Deploy\Scripts\FortrabbitScriptGenerator;
use Igne\LaravelBootUp\Enums\DeploymentEnvironment;
use Igne\LaravelBootUp\Services\GeneratedFilePublisher;

final class DeployScriptCommand extends BootUpCommand
{
    use ResolvesGenerators;

    private const array BUILT_IN_GENERATORS = [
        'fortrabbit' => FortrabbitScriptGenerator::class,
        'forge' => ForgeScriptGenerator::class,
    ];

    protected $signature = 'generate:deploy-script
        {platform? : The hosting platform (fortrabbit, forge, or any generator registered in boot-up.deploy.script_generators)}
        {environment? : The target environment (development, staging, production)}
        {--classic : Forge only — generate for classic (non-zero-downtime) sites}
        {--in-terminal : Print paste-ready commands instead of writing the platform\'s deploy file (platforms without a deploy file always print)}
        {--force : Overwrite an existing deploy file without asking}
        {--output= : Write the script to this file instead of printing it or writing the platform\'s deploy file}';

    protected $description = 'Export a deployment script, or write the platform\'s deploy file, based on this package\'s config';

    public function handle(DeploymentPlanner $planner, GeneratedFilePublisher $publisher): int
    {
        $platform = $this->choose('platform', 'Which platform should the deployment script target?', $this->platformOptions());

        /** @var ScriptGenerator $generator */
        $generator = $this->laravel->make($this->generators()[$platform]);

        $environment = DeploymentEnvironment::from(
            $this->choose('environment', 'Which environment is this script for?', $this->environmentOptions(), DeploymentEnvironment::Production->value),
        );

        $plan = $planner->plan(
            environment: $environment,
            zeroDowntime: ! $this->option('classic'),
            inTerminal: (bool) $this->option('in-terminal'),
        );

        $script = $generator->generate($plan);

        $output = $this->option('output');

        if (\is_string($output) && $output !== '') {
            $this->announce('Generating a deployment script...');
            file_put_contents($output, $script->render());

            return $this->done("Deployment script written to {$output}.");
        }

        if (! $plan->inTerminal && $generator instanceof WritesDeployFile) {
            return $this->writeDeployFile($generator, $script, $publisher);
        }

        $this->printScript($script);

        return self::SUCCESS;
    }

    /**
     * The platform reads its settings from a committed file, so that file is
     * the default output — an existing one is only replaced after the
     * publisher's overwrite confirmation (or --force).
     */
    private function writeDeployFile(WritesDeployFile $generator, Lines $script, GeneratedFilePublisher $publisher): int
    {
        $this->announce('Generating the deploy file...');

        $path = $generator->deployFilePath();

        if (! $publisher->publish([new GeneratedFile($path, $script->render())], (bool) $this->option('force'))) {
            return self::SUCCESS;
        }

        return $this->done("Deploy file written to {$path} — commit it so the platform picks it up.");
    }

    /**
     * @return array<string, class-string<ScriptGenerator>>
     */
    private function generators(): array
    {
        return $this->mergeGenerators(
            self::BUILT_IN_GENERATORS,
            $this->laravel->make(DeployConfig::class)->scriptGenerators,
            'boot-up.deploy.script_generators',
            ScriptGenerator::class,
        );
    }

    /**
     * @return array<string, string>
     */
    private function platformOptions(): array
    {
        return $this->generatorLabels($this->generators());
    }

    /**
     * @return array<string, string>
     */
    private function environmentOptions(): array
    {
        return collect(DeploymentEnvironment::cases())
            ->mapWithKeys(fn (DeploymentEnvironment $environment): array => [
                $environment->value => $environment->value,
            ])
            ->all();
    }

    /**
     * Print the script to stdout. On an interactive terminal comments are
     * dimmed and section headings are bold-cyan so it's clear what to copy;
     * otherwise (redirect, pipe, non-TTY) the raw plain text goes out so
     * `generate:deploy-script forge production > deploy.sh` stays clean.
     */
    private function printScript(Lines $script): void
    {
        $lines = $this->output->isDecorated()
            ? $script->toStyledArray($this->styleLine(...))
            : explode("\n", rtrim($script->render(), "\n"));

        foreach ($lines as $line) {
            $this->line($line);
        }
    }

    private function styleLine(string $kind, string $text): string
    {
        return match ($kind) {
            Lines::KIND_HEADING => terminal()->bold(terminal()->cyan($text)),
            Lines::KIND_WARNING => terminal()->orange($text),
            Lines::KIND_COMMENT => terminal()->dim($text),
            default => $text,
        };
    }
}
