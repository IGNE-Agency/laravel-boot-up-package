<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Environment\Steps;

use Closure;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\EnvironmentConfig;
use Igne\LaravelBootUp\Environment\EnvironmentException;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;

final class EnsureLocalEnvironment implements Step
{
    /**
     * @param  array<string, mixed>|null  $serverVars  Overrides $_SERVER for tests.
     */
    public function __construct(
        private readonly EnvFile $envFile,
        private readonly EnvironmentConfig $config,
        private readonly ?array $serverVars = null,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        // Read APP_ENV from the .env file itself, not the booted framework:
        // a .env created earlier this run has not been loaded yet, and on a
        // blank machine the framework would report 'production'. A missing
        // or empty APP_ENV counts as local.
        $environment = $this->envFile->get('APP_ENV');
        $environment = ($environment === null || $environment === '') ? 'local' : $environment;

        if (! \in_array($environment, $this->config->allowedEnvironments, true)) {
            throw EnvironmentException::unsupportedEnvironment($environment);
        }

        if ($this->isRemoteHost()) {
            throw EnvironmentException::remoteHost();
        }

        return $next($context);
    }

    private function isRemoteHost(): bool
    {
        $vars = $this->serverVars ?? $_SERVER;

        return isset($vars['SSH_CLIENT']) || isset($vars['SSH_TTY']) || isset($vars['SSH_CONNECTION']);
    }
}
