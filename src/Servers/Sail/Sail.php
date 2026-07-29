<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;

final class Sail
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function isInstalled(): bool
    {
        return is_file(base_path('vendor/bin/sail'));
    }

    public function isConfigured(): bool
    {
        return is_file(base_path('docker-compose.yml')) || is_file(base_path('compose.yaml'));
    }

    public function scaffold(): void
    {
        $this->runner->run(CommandLine::make('php artisan sail:install'));
    }

    public function up(bool $build = false): void
    {
        $command = './vendor/bin/sail up -d'.($build ? ' --build' : '');

        // Compose and BuildKit fall back noisily ("TTY mode requires
        // /dev/tty") when stdout is a pipe — select the non-TTY renderers
        // up front.
        $this->runner->run(
            CommandLine::make($command)
                ->withTimeout(null)
                ->withEnv(['BUILDKIT_PROGRESS' => 'plain', 'COMPOSE_ANSI' => 'never']),
        );
    }

    public function hasRunningContainers(): bool
    {
        $result = $this->runner->runSilently(CommandLine::make('./vendor/bin/sail ps -q'));

        return $result->successful() && trim($result->output()) !== '';
    }

    public function down(): void
    {
        $this->runner->run(CommandLine::make('./vendor/bin/sail down'));
    }
}
