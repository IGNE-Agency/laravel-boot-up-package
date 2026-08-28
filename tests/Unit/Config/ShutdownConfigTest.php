<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ShutdownConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.shutdown schema', function (): void {
    $config = ShutdownConfig::fromRepository(new Repository([
        'boot-up' => [
            'shutdown' => ['prompt_stop_server' => false, 'stop_server_by_default' => true],
        ],
    ]));

    expect($config->promptStopServer)->toBeFalse()
        ->and($config->stopServerByDefault)->toBeTrue();
});
