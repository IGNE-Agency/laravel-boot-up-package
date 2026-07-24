<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Data\ShellCommand;
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
        $this->runner->run(ShellCommand::make('php artisan sail:install'));
    }

    public function up(): void
    {
        $this->runner->run(ShellCommand::make('./vendor/bin/sail up -d')->withTimeout(null));
    }

    public function hasRunningContainers(): bool
    {
        $result = $this->runner->runSilently(ShellCommand::make('./vendor/bin/sail ps -q'));

        return $result->successful() && trim($result->output()) !== '';
    }

    public function down(): void
    {
        $this->runner->run(ShellCommand::make('./vendor/bin/sail down'));
    }
}
