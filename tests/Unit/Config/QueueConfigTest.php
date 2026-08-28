<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\QueueConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.queue schema', function (): void {
    $config = new Repository([
        'boot-up' => [
            'queue' => [
                'enabled' => false,
                'flags' => ['--tries' => 3],
            ],
        ],
    ]);

    $queue = QueueConfig::fromRepository($config);

    expect($queue->enabled)->toBeFalse()
        ->and($queue->flags)->toBe(['--tries' => 3]);
});

test('fromRepository falls back to the documented defaults', function (): void {
    $queue = QueueConfig::fromRepository(new Repository);

    expect($queue->enabled)->toBeTrue()
        ->and($queue->flags)->toBe([]);
});
