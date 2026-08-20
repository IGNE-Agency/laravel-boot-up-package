<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Console\BootUpCommand;
use Igne\LaravelBootUp\Console\DevCommand;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\Lines;
use Igne\LaravelBootUp\Exceptions\BootUpException;
use Igne\LaravelBootUp\Services\Terminal;
use Igne\LaravelBootUp\Services\TrackedProgress;
use Igne\LaravelBootUp\Tools\Installers\ToolInstaller;
use Illuminate\Foundation\Console\DevCommand as FrameworkDevCommand;
use Illuminate\Support\Facades\Facade;

arch('all package code uses strict types')
    ->expect('Igne\LaravelBootUp')
    ->toUseStrictTypes();

arch('every class is final except the shared exception and command bases')
    ->expect('Igne\LaravelBootUp')
    ->classes()
    ->toBeFinal()
    // DevCommand is open so tests can replace its handoff to the framework.
    ->ignoring([BootUpException::class, BootUpCommand::class, ToolInstaller::class, DevCommand::class]);

arch('no raw process primitives anywhere — the ProcessRunner is the only OS seam')
    ->expect('Igne\LaravelBootUp')
    ->not->toUse(['exec', 'shell_exec', 'passthru', 'system', 'proc_open', 'popen', 'pcntl_exec'])
    // DevCommand hands the terminal to the command Laravel builds. It has to
    // inherit the foreground, which is what passthru is for -- and the reason
    // it overrides the parent at all is that upstream reaches for pcntl_exec,
    // replacing this process and taking boot-up's teardown with it.
    ->ignoring('Igne\LaravelBootUp\Console\DevCommand');

arch('nothing depends on Symfony Process directly')
    ->expect('Igne\LaravelBootUp')
    ->not->toUse('Symfony\Component\Process\Process');

arch('the process factory is only touched by the Process layer and the provider')
    ->expect('Igne\LaravelBootUp')
    ->not->toUse('Illuminate\Process\Factory')
    ->ignoring(['Igne\LaravelBootUp\Process', 'Igne\LaravelBootUp\Providers\BootUpServiceProvider']);

arch('every pipeline step implements the Step contract')
    ->expect([
        'Igne\LaravelBootUp\Database\Steps',
        'Igne\LaravelBootUp\Deploy\Steps',
        'Igne\LaravelBootUp\Environment\Steps',
        'Igne\LaravelBootUp\Frontend\Steps',
        'Igne\LaravelBootUp\Boot\Steps',
        'Igne\LaravelBootUp\Servers\Steps',
        'Igne\LaravelBootUp\Tools\Steps',
    ])
    ->toImplement(Step::class);

arch('laravel prompts is only touched through the Terminal seam')
    ->expect('Igne\LaravelBootUp')
    ->not->toUse('Laravel\Prompts')
    ->ignoring([Terminal::class, TrackedProgress::class]);

arch('no role-suffix namespaces — classes live with their domain, traits in Concerns')
    ->expect([
        'Igne\LaravelBootUp\Managers',
        'Igne\LaravelBootUp\Executors',
        'Igne\LaravelBootUp\Resolvers',
        'Igne\LaravelBootUp\Repositories',
        'Igne\LaravelBootUp\Verifiers',
        'Igne\LaravelBootUp\Handlers',
        'Igne\LaravelBootUp\Traits',
    ])
    ->not->toBeUsed();

arch('only interfaces live in Contracts')
    ->expect('Igne\LaravelBootUp\Contracts')
    ->toBeInterfaces();

arch('only enums live in Enums')
    ->expect('Igne\LaravelBootUp\Enums')
    ->toBeEnums();

arch('only traits live in Concerns')
    ->expect('Igne\LaravelBootUp\Concerns')
    ->toBeTraits();

arch('Attributes holds only final readonly PHP attributes')
    ->expect('Igne\LaravelBootUp\Attributes')
    ->toBeFinal()
    ->toBeReadonly()
    ->toHaveAttribute(Attribute::class);

arch('config classes are final readonly value objects built from the repository')
    ->expect('Igne\LaravelBootUp\Config')
    ->toBeFinal()
    ->toBeReadonly()
    ->toHaveSuffix('Config')
    ->toHaveMethod('fromRepository');

arch('the Config suffix is reserved for the Config namespace')
    ->expect('Igne\LaravelBootUp')
    ->classes()
    ->not->toHaveSuffix('Config')
    ->ignoring('Igne\LaravelBootUp\Config');

arch('every exception extends the package base and lives in Exceptions')
    ->expect('Igne\LaravelBootUp\Exceptions')
    ->classes()
    ->toExtend(BootUpException::class)
    ->ignoring(BootUpException::class);

arch('the Exception suffix is reserved for the Exceptions namespace')
    ->expect('Igne\LaravelBootUp')
    ->classes()
    ->not->toHaveSuffix('Exception')
    ->ignoring('Igne\LaravelBootUp\Exceptions');

arch('Console contains only boot-up commands')
    ->expect('Igne\LaravelBootUp\Console')
    ->classes()
    ->toExtend(BootUpCommand::class)
    // DevCommand extends Laravel's own dev command instead, so the terminal
    // UI stays upstream's; it applies the same concerns by hand.
    ->ignoring([BootUpCommand::class, DevCommand::class]);

arch('the dev command builds on the framework\'s own')
    ->expect(DevCommand::class)
    ->toExtend(FrameworkDevCommand::class);

arch('the Command suffix is reserved for console commands')
    ->expect('Igne\LaravelBootUp')
    ->classes()
    ->not->toHaveSuffix('Command')
    ->ignoring('Igne\LaravelBootUp\Console');

arch('data objects are readonly values, apart from the two documented carriers')
    ->expect('Igne\LaravelBootUp\Data')
    ->classes()
    ->toBeReadonly()
    ->ignoring([BootContext::class, Lines::class]);

arch('facades stay thin')
    ->expect('Igne\LaravelBootUp\Facades')
    ->toExtend(Facade::class);
