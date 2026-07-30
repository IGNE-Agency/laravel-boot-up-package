<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Contracts\ScriptGenerator;
use Igne\LaravelBootUp\Data\Lines;
use Igne\LaravelBootUp\Deploy\Scripts\DeploymentPlanner;
use Igne\LaravelBootUp\Deploy\Scripts\ForgeScriptGenerator;
use Igne\LaravelBootUp\Deploy\Scripts\FortrabbitScriptGenerator;
use Igne\LaravelBootUp\Enums\DeploymentEnvironment;

final class DeployScriptCommand extends BootUpCommand
{
    private const array BUILT_IN_GENERATORS = [
        'fortrabbit' => FortrabbitScriptGenerator::class,
        'forge' => ForgeScriptGenerator::class,
    ];

    protected $signature = 'generate:deploy-script
        {platform? : The hosting platform (fortrabbit, forge, or any generator registered in boot-up.deploy.script_generators)}
        {environment? : The target environment (development, staging, production)}
        {--classic : Forge only — generate for classic (non-zero-downtime) sites}
        {--output= : Write the script to a file instead of printing it}';

    protected $description = 'Export a deployment script for a hosting platform, based on this package\'s config';

    public function handle(DeploymentPlanner $planner): int
    {
        $platform = $this->choose('platform', 'Which platform should the deployment script target?');

        /** @var ScriptGenerator $generator */
        $generator = $this->laravel->make($this->generators()[$platform]);

        $environment = DeploymentEnvironment::from(
            $this->choose('environment', 'Which environment is this script for?', DeploymentEnvironment::PRODUCTION->value),
        );

        $script = $generator->generate($planner->plan(
            environment: $environment,
            zeroDowntime: ! $this->option('classic'),
        ));

        $output = $this->option('output');

        if (\is_string($output) && $output !== '') {
            $this->announce('Generating a deployment script...');
            file_put_contents($output, $script->render());

            return $this->done("Deployment script written to {$output}.");
        }

        $this->printScript($script);

        return self::SUCCESS;
    }

    /**
     * @return array<string, class-string<ScriptGenerator>>
     */
    private function generators(): array
    {
        return array_merge(self::BUILT_IN_GENERATORS, $this->laravel->make(DeployConfig::class)->scriptGenerators);
    }

    /**
     * @return array<string, string>
     */
    protected function platformOptions(): array
    {
        return collect($this->generators())
            ->map(fn (string $class): string => $this->laravel->make($class)->label())
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function environmentOptions(): array
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
        if (! $this->output->isDecorated()) {
            foreach (explode("\n", rtrim($script->render(), "\n")) as $line) {
                $this->line($line);
            }

            return;
        }

        $styler = fn (string $kind, string $text): string => match ($kind) {
            Lines::KIND_HEADING => terminal()->bold(terminal()->cyan($text)),
            Lines::KIND_WARNING => terminal()->orange($text),
            Lines::KIND_COMMENT => terminal()->dim($text),
            default => $text,
        };

        foreach ($script->toStyledArray($styler) as $line) {
            $this->line($line);
        }
    }
}
