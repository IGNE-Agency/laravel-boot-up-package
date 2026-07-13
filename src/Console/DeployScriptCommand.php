<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Console;

use Igne\LaravelBootstrap\Deploy\DeployConfig;
use Igne\LaravelBootstrap\Deploy\Scripts\DeploymentEnvironment;
use Igne\LaravelBootstrap\Deploy\Scripts\DeploymentPlanner;
use Igne\LaravelBootstrap\Deploy\Scripts\ForgeScriptGenerator;
use Igne\LaravelBootstrap\Deploy\Scripts\FortrabbitScriptGenerator;
use Igne\LaravelBootstrap\Deploy\Scripts\ScriptGenerator;
use Illuminate\Console\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

final class DeployScriptCommand extends Command
{
    private const BUILT_IN_GENERATORS = [
        'forge' => ForgeScriptGenerator::class,
        'fortrabbit' => FortrabbitScriptGenerator::class,
    ];

    protected $signature = 'app:deploy-script
        {platform? : The hosting platform (forge, fortrabbit)}
        {environment? : The target environment (development, staging, production)}
        {--classic : Forge only — generate for classic (non-zero-downtime) sites}
        {--output= : Write the script to a file instead of printing it}';

    protected $description = 'Export a deployment script for a hosting platform, based on this package\'s config';

    public function handle(DeploymentPlanner $planner, DeployConfig $config): int
    {
        $generators = array_merge(self::BUILT_IN_GENERATORS, $config->scriptGenerators);

        $platform = $this->platform($generators);

        if (! isset($generators[$platform])) {
            error("Unknown platform [{$platform}]. Available: ".implode(', ', array_keys($generators)));

            return self::FAILURE;
        }

        /** @var ScriptGenerator $generator */
        $generator = $this->laravel->make($generators[$platform]);

        $script = $generator->generate($planner->plan(
            environment: $this->environment(),
            zeroDowntime: ! $this->option('classic'),
        ));

        $output = $this->option('output');

        if (\is_string($output) && $output !== '') {
            file_put_contents($output, $script);
            note("Deployment script written to {$output}.");

            return self::SUCCESS;
        }

        foreach (explode("\n", rtrim($script, "\n")) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, class-string<ScriptGenerator>>  $generators
     */
    private function platform(array $generators): string
    {
        $argument = $this->argument('platform');

        if (\is_string($argument) && $argument !== '') {
            return strtolower($argument);
        }

        $options = [];

        foreach ($generators as $key => $class) {
            $options[$key] = $this->laravel->make($class)->label();
        }

        return (string) select('Which platform should the deployment script target?', $options);
    }

    private function environment(): DeploymentEnvironment
    {
        $argument = $this->argument('environment');

        if (\is_string($argument) && $argument !== '') {
            return DeploymentEnvironment::from(strtolower($argument));
        }

        return DeploymentEnvironment::from((string) select(
            label: 'Which environment is this script for?',
            options: array_column(DeploymentEnvironment::cases(), 'value', 'value'),
            default: DeploymentEnvironment::PRODUCTION->value,
        ));
    }
}
