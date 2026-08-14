<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\BootProcessKind;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Enums\RegistrationSource;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Enums\StreamColor;
use Igne\LaravelBootUp\Enums\StreamPosition;

function pendingProcess(BootProcessKind $kind = BootProcessKind::Shell, string $command = 'stripe listen'): Igne\LaravelBootUp\Serve\PendingBootProcess
{
    return new Igne\LaravelBootUp\Serve\PendingBootProcess($kind, $command, 'stripe', RegistrationSource::Application);
}

test('a fresh registration streams combined, uncolored, unplaced', function (): void {
    $process = pendingProcess();

    expect($process->runIn())->toBe(RunMode::Combined)
        ->and($process->pickedColor())->toBeNull()
        ->and($process->placement())->toBeNull();
});

test('every color method picks its palette case', function (): void {
    expect(pendingProcess()->blue()->pickedColor())->toBe(StreamColor::Blue)
        ->and(pendingProcess()->purple()->pickedColor())->toBe(StreamColor::Purple)
        ->and(pendingProcess()->pink()->pickedColor())->toBe(StreamColor::Pink)
        ->and(pendingProcess()->orange()->pickedColor())->toBe(StreamColor::Orange)
        ->and(pendingProcess()->green()->pickedColor())->toBe(StreamColor::Green)
        ->and(pendingProcess()->yellow()->pickedColor())->toBe(StreamColor::Yellow)
        ->and(pendingProcess()->color(StreamColor::Pink)->pickedColor())->toBe(StreamColor::Pink);
});

test('run modes and placements chain, the last placement wins', function (): void {
    $process = pendingProcess()->inTerminal()->first()->after('queue');

    expect($process->runIn())->toBe(RunMode::Terminal)
        ->and($process->placement())->toBe(StreamPosition::After)
        ->and($process->placementTarget())->toBe('queue');

    expect(pendingProcess()->inBackground()->runIn())->toBe(RunMode::Background)
        ->and(pendingProcess()->before('vite')->placement())->toBe(StreamPosition::Before)
        ->and(pendingProcess()->last()->placementTarget())->toBeNull();
});

test('the command line resolves lazily with env and directory applied', function (): void {
    $command = pendingProcess(BootProcessKind::PackageManager, 'run dev')
        ->env(['FOO' => '1'])
        ->env(['BAR' => '2'])
        ->in('/srv/app')
        ->commandLine(PackageManager::PNPM);

    expect($command->tokens)->toBe(['pnpm', 'run', 'dev'])
        ->and($command->env)->toBe(['FOO' => '1', 'BAR' => '2'])
        ->and($command->cwd)->toBe('/srv/app');
});
