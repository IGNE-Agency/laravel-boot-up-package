<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Herd;

use Igne\LaravelBootUp\Config\ServersConfig;
use Igne\LaravelBootUp\Contracts\RequiresTools;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Contracts\WarnsBeforeStop;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ShellCommand;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Process\ProcessRunner;

final class HerdServer implements RequiresTools, RewritesCommands, Server, WarnsBeforeStop
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

    /**
     * @return list<Tool>
     */
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

    public function stopImpact(): string
    {
        return '`herd stop` halts ALL Herd sites on this machine, not just this project.';
    }

    public function start(ServeContext $context): void
    {
        $project = $this->project();

        $linked = $this->sites->nameFor($project);

        if ($linked !== null) {
            // An already-linked site is already secured — re-running `herd
            // secure` on every serve regenerates the cert and reloads Nginx,
            // which briefly refuses connections right as the reachability probe
            // starts and made app:serve wrongly report Herd as "not answering".
            terminal()->note("Project already linked to Laravel Herd as https://{$linked}.test.");
        } else {
            $name = $this->claimSiteName($project);

            $this->runOrFail(['herd', 'link', $name]);
            terminal()->success("Project linked to Laravel Herd as https://{$name}.test.");

            $this->secure($name);
        }

        $this->ensureServing();
    }

    public function isRunning(): bool
    {
        return $this->services->isRunning();
    }

    /**
     * A linked, secured site is not a working one: Herd's daemons must be up
     * and Nginx must actually answer. Boot Herd if its processes are down,
     * then wait for the site to respond (restarting an unhealthy Nginx along
     * the way) before app:serve reports the server ready.
     */
    private function ensureServing(): void
    {
        if (! $this->services->isRunning()) {
            terminal()->info('Starting Herd services...');
            $this->services->boot();
        }

        terminal()->info('Verifying Laravel Herd is reachable...');
        $this->services->ensureReachable($this->url());
        terminal()->success("Laravel Herd is serving {$this->url()}.");
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
            terminal()->warning("Herd linked [{$name}] to {$target}, which no longer exists — relinking to this project.");
            $this->runOrFail(['herd', 'unlink', $name]);

            return true;
        }

        if (! terminal()->confirm("Herd already links [{$name}] to {$target}. Replace it with this project?", default: false)) {
            return false;
        }

        $this->runOrFail(['herd', 'unlink', $name]);

        return true;
    }

    private function promptForName(string $project): string
    {
        return (string) terminal()->text(
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
        terminal()->success('HTTPS certificate configured.');
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
