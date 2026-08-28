<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Environment;

use Igne\LaravelBootUp\Config\EnvironmentConfig;

/**
 * The one definition of "this machine may run boot-up".
 *
 * Two callers need the same answers — ProjectReadiness reports problems
 * before `php artisan dev` starts, EnsureLocalEnvironment refuses an app:up
 * outright — and each used to carry its own copy of the rule. Both answer
 * through this class now, so the report and the enforcement cannot drift.
 */
final class LocalEnvironment
{
    /**
     * @param  array<string, mixed>|null  $serverVars  Overrides $_SERVER for tests.
     */
    public function __construct(
        private readonly EnvFile $envFile,
        private readonly EnvironmentConfig $config,
        private readonly ?array $serverVars = null,
    ) {}

    /**
     * Read from the .env file itself, not the booted framework: a .env
     * created earlier this run has not been loaded yet, and on a machine
     * with no .env the framework reports 'production', which would refuse
     * every fresh clone. A missing or empty APP_ENV counts as local.
     */
    public function name(): string
    {
        return $this->envFile->valueOr('APP_ENV', 'local');
    }

    public function isAllowed(): bool
    {
        return \in_array($this->name(), $this->config->allowed, true);
    }

    /**
     * @return list<string>
     */
    public function allowed(): array
    {
        return $this->config->allowed;
    }

    public function isRemoteHost(): bool
    {
        $vars = $this->serverVars ?? $_SERVER;

        return isset($vars['SSH_CLIENT']) || isset($vars['SSH_TTY']) || isset($vars['SSH_CONNECTION']);
    }
}
