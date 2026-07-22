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

final class DeployScriptCommand extends BootUpCommand
{
    private const BUILT_IN_GENERATORS = [
        'fortrabbit' => FortrabbitScriptGenerator::class,
        'forge' => ForgeScriptGenerator::class,
    ];

    protected $signature = 'app:deploy-script
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
            file_put_contents($output, $script);
            terminal()->outro("Deployment script written to {$output}.");

            return self::SUCCESS;
        }

        // No intro/outro here on purpose: the raw script goes to stdout, so
        // `app:deploy-script forge production > deploy.sh` must stay clean.
        foreach (explode("\n", rtrim($script, "\n")) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
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
