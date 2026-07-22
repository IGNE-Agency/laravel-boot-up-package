<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Console\Support\Selection;
use Igne\LaravelBootUp\Deploy\DeployConfig;
use Igne\LaravelBootUp\Deploy\Scripts\DeploymentEnvironment;
use Igne\LaravelBootUp\Deploy\Scripts\DeploymentPlanner;
use Igne\LaravelBootUp\Deploy\Scripts\ForgeScriptGenerator;
use Igne\LaravelBootUp\Deploy\Scripts\FortrabbitScriptGenerator;
use Igne\LaravelBootUp\Deploy\Scripts\ScriptGenerator;
use Igne\LaravelBootUp\Support\Lines;

final class DeployScriptCommand extends BootUpCommand
{
    private const BUILT_IN_GENERATORS = [
        'fortrabbit' => FortrabbitScriptGenerator::class,
        'forge' => ForgeScriptGenerator::class,
    ];

    protected $signature = 'generate:deploy-script
        {platform? : The hosting platform (fortrabbit, forge, or any generator registered in boot-up.deploy.script_generators)}
        {environment? : The target environment (development, staging, production)}
        {--classic : Forge only — generate for classic (non-zero-downtime) sites}
        {--output= : Write the script to a file instead of printing it}';

    protected $description = 'Export a deployment script for a hosting platform, based on this package\'s config';

    public function perform(DeploymentPlanner $planner, DeployConfig $config, Selection $selection): int
    {
        $generators = array_merge(self::BUILT_IN_GENERATORS, $config->scriptGenerators);

        $platform = $this->platform($generators, $selection);

        if (! isset($generators[$platform])) {
            terminal()->error("Unknown platform [{$platform}]. Available: ".implode(', ', array_keys($generators)));

            return self::FAILURE;
        }

        /** @var ScriptGenerator $generator */
        $generator = $this->laravel->make($generators[$platform]);

        $environment = $this->environment($selection);

        if ($environment === null) {
            terminal()->error('Unknown environment. Available: '.implode(', ', array_column(DeploymentEnvironment::cases(), 'value')));

            return self::FAILURE;
        }

        $script = $generator->generate($planner->plan(
            environment: $environment,
            zeroDowntime: ! $this->option('classic'),
        ));

        $output = $this->option('output');

        if (\is_string($output) && $output !== '') {
            terminal()->intro('Generating a deployment script...');
            file_put_contents($output, $script->render());
            terminal()->outro("Deployment script written to {$output}.");

            return self::SUCCESS;
        }

        $this->printScript($script);

        return self::SUCCESS;
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

    /**
     * @param  array<string, class-string<ScriptGenerator>>  $generators
     */
    private function platform(array $generators, Selection $selection): string
    {
        $options = [];

        foreach ($generators as $key => $class) {
            $options[$key] = $this->laravel->make($class)->label();
        }

        return $selection->resolve($this->argument('platform'), $options, 'Which platform should the deployment script target?');
    }

    private function environment(Selection $selection): ?DeploymentEnvironment
    {
        $choice = $selection->resolve(
            $this->argument('environment'),
            array_column(DeploymentEnvironment::cases(), 'value', 'value'),
            'Which environment is this script for?',
            DeploymentEnvironment::PRODUCTION->value,
        );

        return DeploymentEnvironment::tryFrom($choice);
    }
}
