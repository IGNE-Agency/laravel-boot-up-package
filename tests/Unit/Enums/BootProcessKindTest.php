<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\BootProcessKind;
use Igne\LaravelBootUp\Enums\PackageManager;

test('each kind builds its command line', function (): void {
    expect(BootProcessKind::Shell->commandLine('stripe listen --forward-to http://localhost', PackageManager::BUN)->tokens)
        ->toBe(['stripe', 'listen', '--forward-to', 'http://localhost'])
        ->and(BootProcessKind::Artisan->commandLine('reverb:start --debug', PackageManager::BUN)->tokens)
        ->toBe(['php', 'artisan', 'reverb:start', '--debug'])
        ->and(BootProcessKind::PackageManager->commandLine('run dev', PackageManager::PNPM)->tokens)
        ->toBe(['pnpm', 'run', 'dev'])
        ->and(BootProcessKind::PackageManagerExec->commandLine('vite --port 3000', PackageManager::PNPM)->tokens)
        ->toBe(['pnpm', 'exec', 'vite', '--port', '3000'])
        ->and(BootProcessKind::PackageManagerExec->commandLine('vite', PackageManager::BUN)->tokens)
        ->toBe(['bunx', 'vite']);
});

test('the default name is the first token', function (): void {
    expect(BootProcessKind::Shell->defaultName('stripe listen --forward-to http://localhost'))->toBe('stripe')
        ->and(BootProcessKind::Artisan->defaultName('reverb:start'))->toBe('reverb:start')
        ->and(BootProcessKind::PackageManagerExec->defaultName('vite --port 3000'))->toBe('vite');
});

test('a package-manager run script names itself after the script', function (): void {
    expect(BootProcessKind::PackageManager->defaultName('run dev'))->toBe('dev')
        ->and(BootProcessKind::PackageManager->defaultName('install'))->toBe('install');
});
