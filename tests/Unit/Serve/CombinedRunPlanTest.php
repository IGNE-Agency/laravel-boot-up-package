<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\CombinedService;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Serve\CombinedRunPlan;

test('collects services in insertion order', function (): void {
    $plan = new CombinedRunPlan;

    expect($plan->isEmpty())->toBeTrue();

    $plan->add(CombinedService::tail('artisan-serve', 'server', '/logs/artisan-serve.log'));
    $plan->add(CombinedService::process('queue-worker', 'queue', CommandLine::make('php artisan queue:work')));

    expect($plan->isEmpty())->toBeFalse()
        ->and($plan->services())->toHaveCount(2)
        ->and($plan->services()[0]->name)->toBe('server')
        ->and($plan->services()[1]->isProcess())->toBeTrue();
});

test('a tail-only plan reports no processes', function (): void {
    $plan = new CombinedRunPlan;
    $plan->add(CombinedService::tail('artisan-serve', 'server', '/logs/artisan-serve.log'));

    expect($plan->hasProcesses())->toBeFalse();

    $plan->add(CombinedService::process('queue-worker', 'queue', CommandLine::make('php artisan queue:work')));

    expect($plan->hasProcesses())->toBeTrue();
});
