<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Pipelines;

use Igne\LaravelBootstrap\Deploy\ProjectCommand;
use Igne\LaravelBootstrap\Deploy\ProjectCommandType;
use Igne\LaravelBootstrap\Frontend\PackageManager;

/**
 * The shell commands every pipeline runs, shared by all generators so the
 * providers execute the identical sequence and cannot drift apart.
 */
final class PipelineSteps
{
    /**
     * @return list<string>
     */
    public function composerInstall(): array
    {
        return ['composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress'];
    }

    /**
     * @return list<string>
     */
    public function prepareEnvironment(PipelinePlan $plan): array
    {
        return [
            "cp {$plan->envFile} .env",
            'mkdir -p storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views',
        ];
    }

    /**
     * @return list<string>
     */
    public function novaPublish(PipelinePlan $plan): array
    {
        return $plan->nova ? ['php artisan nova:publish'] : [];
    }

    /**
     * @return list<string>
     */
    public function frontend(PipelinePlan $plan): array
    {
        if (! $plan->deployment->frontend) {
            return [];
        }

        $manager = $plan->deployment->packageManager;
        $lines = [];

        // Only npm is guaranteed on both providers' images.
        if ($manager !== PackageManager::NPM) {
            $lines[] = "npm i -g {$manager->value}";
        }

        $lines[] = $manager->ciInstallLine();
        $lines[] = "{$manager->value} run build";

        return $lines;
    }

    /**
     * @return list<string>
     */
    public function testSuite(PipelinePlan $plan): array
    {
        $lines = ['php artisan config:clear'];

        foreach ($plan->deployment->finalize as $command) {
            $lines[] = "php artisan {$command}";
        }

        foreach ($plan->deployment->beforeMigrations as $command) {
            $lines = [...$lines, ...$this->projectCommand($command, $plan)];
        }

        if ($plan->deployment->migrate) {
            $lines[] = 'php artisan migrate --force';
        }

        foreach ($plan->deployment->afterMigrations as $command) {
            $lines = [...$lines, ...$this->projectCommand($command, $plan)];
        }

        $lines[] = 'php artisan test';

        return $lines;
    }

    /**
     * The full install-test body, in order.
     *
     * @return list<string>
     */
    public function installTest(PipelinePlan $plan): array
    {
        return [
            ...$this->composerInstall(),
            ...$this->prepareEnvironment($plan),
            ...$this->novaPublish($plan),
            ...$this->frontend($plan),
            ...$this->testSuite($plan),
        ];
    }

    /**
     * Curl the deploy webhook held in $variable, skipping gracefully when it
     * is unset. $name and $branch may be literals or shell expansions, so the
     * printed message is identical across providers.
     *
     * @return list<string>
     */
    public function deployHook(string $variable, string $name, string $branch): array
    {
        return [
            "if [ -z \"\${$variable}\" ]; then echo \"{$name} is not set — skipping deploy for {$branch}.\"; exit 0; fi",
            "curl --silent --show-error --fail-with-body -X POST \"\${$variable}\"",
        ];
    }

    /**
     * The branches as prose for generated header comments,
     * e.g. "develop, staging and master".
     */
    public function branchList(PipelinePlan $plan): string
    {
        $branches = array_keys($plan->branchHooks);
        $last = array_pop($branches);

        return $branches === [] ? (string) $last : implode(', ', $branches).' and '.$last;
    }

    /**
     * @return list<string>
     */
    private function projectCommand(ProjectCommand $command, PipelinePlan $plan): array
    {
        $lines = [];

        // Descriptions render as echo, not "# …": a leading # inside a YAML
        // sequence item would be parsed as a YAML comment on Bitbucket.
        if ($command->description !== null) {
            $lines[] = 'echo "'.str_replace(['\\', '"'], ['\\\\', '\"'], $command->description).'"';
        }

        $lines[] = match ($command->type) {
            ProjectCommandType::ARTISAN => "php artisan {$command->command}",
            ProjectCommandType::COMPOSER => "composer {$command->command}",
            ProjectCommandType::PACKAGE_MANAGER => "{$plan->deployment->packageManager->value} {$command->command}",
        };

        return $lines;
    }
}
