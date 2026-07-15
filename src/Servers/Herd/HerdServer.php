<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Herd;

use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Servers\CommandRewrites;
use Igne\LaravelBootUp\Servers\Server;
use Igne\LaravelBootUp\Servers\ServerException;
use Igne\LaravelBootUp\Servers\ServersConfig;
use Igne\LaravelBootUp\Tools\Tool;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

final class HerdServer implements Server
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly HerdServices $services,
        private readonly HerdSites $sites,
        private readonly ServersConfig $config,
        private readonly ?string $projectPath = null,
    ) {}

    public function key(): string
    {
        return 'herd';
    }

    public function label(): string
    {
        return 'Laravel Herd';
    }

    public function requiredTools(): array
    {
        return [Tool::HERD];
    }

    public function commandRewrites(): CommandRewrites
    {
        return new CommandRewrites(
            prefixes: ['php', 'composer', 'tinker'],
            prefix: 'herd',
        );
    }

    public function start(ServeContext $context): void
    {
        $project = $this->project();

        $linked = $this->sites->nameFor($project);

        if ($linked !== null) {
            info("Project already linked to Herd as https://{$linked}.test.");
            $this->secure($linked);

            return;
        }

        $name = $this->claimSiteName($project);

        $this->runOrFail(['herd', 'link', $name]);
        info("Project linked to Herd as https://{$name}.test.");

        $this->secure($name);
    }

    public function isRunning(): bool
    {
        return $this->services->isHealthy();
    }

    public function stop(): void
    {
        $this->runner->run(ShellCommand::make('herd stop'));
    }

    /**
     * Herd serves the site at https://{name}.test where {name} is whatever
     * the registry links to this project — the directory name is only the
     * fallback for unlinked projects. config('app.url') is wrong on a
     * fresh .env, so it is never consulted.
     */
    public function url(): string
    {
        $project = $this->project();

        return 'https://'.($this->sites->nameFor($project) ?? basename($project)).'.test';
    }

    private function project(): string
    {
        return $this->projectPath ?? (getcwd() ?: '');
    }

    /**
     * Resolve a site name this project may link as: the configured name or
     * a prompt (defaulting to the folder name), retrying while the name is
     * claimed by another live project the user declines to replace. Stale
     * links — targets that no longer exist, e.g. a moved project — are
     * unlinked automatically so they cannot 404 the domain.
     */
    private function claimSiteName(string $project): string
    {
        $name = $this->config->herdSite ?? $this->promptForName($project);

        while (! $this->claim($name)) {
            $name = $this->promptForName($project);
        }

        return $name;
    }

    private function claim(string $name): bool
    {
        $target = $this->sites->linkedPath($name);

        if ($target === null) {
            return true;
        }

        if (! is_dir($target)) {
            warning("Herd linked [{$name}] to {$target}, which no longer exists — relinking to this project.");
            $this->runOrFail(['herd', 'unlink', $name]);

            return true;
        }

        if (! confirm("Herd already links [{$name}] to {$target}. Replace it with this project?", default: false)) {
            return false;
        }

        $this->runOrFail(['herd', 'unlink', $name]);

        return true;
    }

    private function promptForName(string $project): string
    {
        return (string) text(
            label: 'What should the Herd site be called?',
            default: basename($project),
            hint: 'The site is served at https://{name}.test.',
            validate: fn (string $value): ?string => preg_match('/^[a-zA-Z0-9._-]+$/', $value) === 1
                ? null
                : 'Use only letters, numbers, dots, dashes and underscores.',
        );
    }

    private function secure(string $name): void
    {
        $this->runOrFail(['herd', 'secure', $name]);
        info('HTTPS certificate configured.');
    }

    /**
     * @param  list<string>  $command
     */
    private function runOrFail(array $command): void
    {
        $result = $this->runner->runSilently(ShellCommand::make($command));

        if (! $result->successful()) {
            throw ServerException::startFailed(
                $this->label(),
                '`'.implode(' ', $command).'` failed: '.trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output()),
            );
        }
    }
}
