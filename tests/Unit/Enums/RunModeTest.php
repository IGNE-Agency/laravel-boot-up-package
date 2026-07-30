<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Exceptions\ConfigException;

test('fromConfig maps the documented values', function (): void {
    expect(RunMode::fromConfig('combined', 'boot-up.queue.run_in', RunMode::Combined))->toBe(RunMode::Combined)
        ->and(RunMode::fromConfig('terminal', 'boot-up.queue.run_in', RunMode::Combined))->toBe(RunMode::Terminal)
        ->and(RunMode::fromConfig('background', 'boot-up.queue.run_in', RunMode::Combined))->toBe(RunMode::Background);
});

test('null and the empty string mean the default', function (): void {
    expect(RunMode::fromConfig(null, 'boot-up.queue.run_in', RunMode::Combined))->toBe(RunMode::Combined)
        ->and(RunMode::fromConfig('', 'boot-up.queue.run_in', RunMode::Terminal))->toBe(RunMode::Terminal);
});

test('an unknown string throws a ConfigException naming the key and legal values', function (): void {
    expect(fn () => RunMode::fromConfig('sideways', 'boot-up.queue.run_in', RunMode::Combined))
        ->toThrow(ConfigException::class, 'boot-up.queue.run_in')
        ->toThrow(ConfigException::class, 'combined, terminal, background');
});
