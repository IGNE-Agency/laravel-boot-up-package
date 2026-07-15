<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Serve\Step;
use Igne\LaravelBootstrap\Support\BootstrapException;

arch('all package code uses strict types')
    ->expect('Igne\LaravelBootstrap')
    ->toUseStrictTypes();

arch('every class is final except the shared exception base')
    ->expect('Igne\LaravelBootstrap')
    ->classes()
    ->toBeFinal()
    ->ignoring(BootstrapException::class);

arch('no raw process primitives anywhere — the ProcessRunner is the only OS seam')
    ->expect('Igne\LaravelBootstrap')
    ->not->toUse(['exec', 'shell_exec', 'passthru', 'system', 'proc_open', 'popen', 'pcntl_exec']);

arch('nothing depends on Symfony Process directly')
    ->expect('Igne\LaravelBootstrap')
    ->not->toUse('Symfony\Component\Process\Process');

arch('the process factory is only touched by the Process layer and the provider')
    ->expect('Igne\LaravelBootstrap')
    ->not->toUse('Illuminate\Process\Factory')
    ->ignoring(['Igne\LaravelBootstrap\Process', 'Igne\LaravelBootstrap\BootstrapServiceProvider']);

arch('every pipeline step implements the Step contract')
    ->expect([
        'Igne\LaravelBootstrap\Database\Steps',
        'Igne\LaravelBootstrap\Deploy\Steps',
        'Igne\LaravelBootstrap\Environment\Steps',
        'Igne\LaravelBootstrap\Frontend\Steps',
        'Igne\LaravelBootstrap\Queue\Steps',
        'Igne\LaravelBootstrap\Serve\Steps',
        'Igne\LaravelBootstrap\Servers\Steps',
        'Igne\LaravelBootstrap\Tools\Steps',
    ])
    ->toImplement(Step::class);

arch('legacy namespaces are gone for good')
    ->expect([
        'Igne\LaravelBootstrap\Managers',
        'Igne\LaravelBootstrap\Executors',
        'Igne\LaravelBootstrap\Resolvers',
        'Igne\LaravelBootstrap\Providers',
        'Igne\LaravelBootstrap\Repositories',
        'Igne\LaravelBootstrap\Verifiers',
        'Igne\LaravelBootstrap\Handlers',
        'Igne\LaravelBootstrap\Traits',
    ])
    ->not->toBeUsed();
