<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ProcessConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.process schema', function (): void {
    $config = ProcessConfig::fromRepository(new Repository([
        'boot-up' => [
            'process' => ['terminal_pid_timeout' => 5],
        ],
    ]));

    expect($config->terminalPidTimeout)->toBe(5);
});
