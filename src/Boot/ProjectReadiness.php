<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

use Igne\LaravelBootUp\Config\EnvironmentConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Frontend\PackageJson;

/**
 * Whether a project can run its dev processes, and why not when it cannot.
 *
 * Filesystem reads and one $_SERVER lookup, nothing more: `php artisan dev`
 * has to reach the terminal UI in milliseconds, and anything that takes real
 * work to answer is app:setup's job. What this catches is the project that
 * was never set up, so the user is told to set it up rather than watching
 * every tab crash-loop.
 */
final class ProjectReadiness
{
    /**
     * @param  array<string, mixed>|null  $serverVars  Overrides $_SERVER for tests.
     */
    public function __construct(
        private readonly EnvFile $envFile,
        private readonly PackageJson $packageJson,
        private readonly FrontendConfig $frontendConfig,
        private readonly EnvironmentConfig $environmentConfig,
        private readonly string $basePath,
        private readonly ?array $serverVars = null,
    ) {}

    /**
     * Why the dev processes cannot run, in the order a person would fix it.
     * An empty list means they can.
     *
     * @return list<string>
     */
    public function problems(BootOptions $options): array
    {
        return array_values(array_filter([
            $this->environment(),
            $this->remoteHost(),
            $this->dotEnv(),
            $this->composerDependencies(),
            $this->frontendDependencies($options),
        ]));
    }

    /**
     * Read from the .env rather than the booted framework: on a machine with
     * no .env the framework reports 'production', which would refuse every
     * fresh clone. A missing or empty APP_ENV counts as local.
     */
    private function environment(): ?string
    {
        $environment = $this->envFile->get('APP_ENV');
        $environment = ($environment === null || $environment === '') ? 'local' : $environment;

        if (\in_array($environment, $this->environmentConfig->allowed, true)) {
            return null;
        }

        $allowed = implode(', ', $this->environmentConfig->allowed);

        return "APP_ENV is [{$environment}]; boot-up only runs in: {$allowed}.";
    }

    private function remoteHost(): ?string
    {
        $vars = $this->serverVars ?? $_SERVER;

        return isset($vars['SSH_CLIENT']) || isset($vars['SSH_TTY']) || isset($vars['SSH_CONNECTION'])
            ? 'This looks like a remote machine (SSH); boot-up is for local development.'
            : null;
    }

    private function dotEnv(): ?string
    {
        if (! $this->envFile->exists()) {
            return 'There is no .env file.';
        }

        $key = $this->envFile->get('APP_KEY');

        return $key === null || $key === '' ? 'APP_KEY is not set in .env.' : null;
    }

    private function composerDependencies(): ?string
    {
        return is_file($this->basePath.'/vendor/autoload.php')
            ? null
            : 'Composer dependencies are not installed.';
    }

    /**
     * Mirrors the asset watcher's own gate, so a run that would not start a
     * watcher is never asked for node_modules.
     */
    private function frontendDependencies(BootOptions $options): ?string
    {
        $wanted = $options->withAssets
            && $this->frontendConfig->assets === AssetMode::Watch
            && $this->packageJson->exists()
            && $this->packageJson->hasScript('dev');

        return $wanted && ! is_dir($this->basePath.'/node_modules')
            ? 'Frontend dependencies are not installed (there is no node_modules).'
            : null;
    }
}
