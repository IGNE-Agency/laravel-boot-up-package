<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Exceptions\ConfigException;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.queue schema', function (): void {
    $config = new Repository([
        'boot-up' => [
            'queue' => [
                'enabled' => false,
                'run_in' => 'terminal',
                'flags' => ['--tries' => 3],
            ],
        ],
    ]);

    $queue = QueueConfig::fromRepository($config);

    expect($queue->enabled)->toBeFalse()
        ->and($queue->runIn)->toBe(RunMode::Terminal)
        ->and($queue->flags)->toBe(['--tries' => 3]);
});

test('fromRepository falls back to the documented defaults', function (): void {
    $queue = QueueConfig::fromRepository(new Repository);

    expect($queue->enabled)->toBeTrue()
        ->and($queue->runIn)->toBe(RunMode::Combined)
        ->and($queue->flags)->toBe([]);
});

test('an unknown run_in string throws a ConfigException naming the key', function (): void {
    $config = new Repository([
        'boot-up' => ['queue' => ['run_in' => 'sideways']],
    ]);

    expect(fn () => QueueConfig::fromRepository($config))
        ->toThrow(ConfigException::class, 'boot-up.queue.run_in');
});
