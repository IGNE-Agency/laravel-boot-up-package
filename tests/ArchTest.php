<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Serve\Step;
use Igne\LaravelBootUp\Support\BootUpException;

arch('all package code uses strict types')
    ->expect('Igne\LaravelBootUp')
    ->toUseStrictTypes();

arch('every class is final except the shared exception base')
    ->expect('Igne\LaravelBootUp')
    ->classes()
    ->toBeFinal()
    ->ignoring(BootUpException::class);

arch('no raw process primitives anywhere — the ProcessRunner is the only OS seam')
    ->expect('Igne\LaravelBootUp')
    ->not->toUse(['exec', 'shell_exec', 'passthru', 'system', 'proc_open', 'popen', 'pcntl_exec']);

arch('nothing depends on Symfony Process directly')
    ->expect('Igne\LaravelBootUp')
    ->not->toUse('Symfony\Component\Process\Process');

arch('the process factory is only touched by the Process layer and the provider')
    ->expect('Igne\LaravelBootUp')
    ->not->toUse('Illuminate\Process\Factory')
    ->ignoring(['Igne\LaravelBootUp\Process', 'Igne\LaravelBootUp\BootUpServiceProvider']);

arch('every pipeline step implements the Step contract')
    ->expect([
        'Igne\LaravelBootUp\Database\Steps',
        'Igne\LaravelBootUp\Deploy\Steps',
        'Igne\LaravelBootUp\Environment\Steps',
        'Igne\LaravelBootUp\Frontend\Steps',
        'Igne\LaravelBootUp\Queue\Steps',
        'Igne\LaravelBootUp\Serve\Steps',
        'Igne\LaravelBootUp\Servers\Steps',
        'Igne\LaravelBootUp\Tools\Steps',
    ])
    ->toImplement(Step::class);

arch('legacy namespaces are gone for good')
    ->expect([
        'Igne\LaravelBootUp\Managers',
        'Igne\LaravelBootUp\Executors',
        'Igne\LaravelBootUp\Resolvers',
        'Igne\LaravelBootUp\Providers',
        'Igne\LaravelBootUp\Repositories',
        'Igne\LaravelBootUp\Verifiers',
        'Igne\LaravelBootUp\Handlers',
        'Igne\LaravelBootUp\Traits',
    ])
    ->not->toBeUsed();
