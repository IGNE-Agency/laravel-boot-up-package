<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\CombinedService;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Enums\RegistrationSource;
use Igne\LaravelBootUp\Serve\BootCommandRegistry;
use Igne\LaravelBootUp\Serve\StreamOrder;

function orderRegistry(): BootCommandRegistry
{
    return new BootCommandRegistry(runningInConsole: true, vendorPath: '/nonexistent/vendor');
}

function service(string $name): CombinedService
{
    return CombinedService::process($name, $name, CommandLine::make('sleep 1'));
}

/**
 * @param  list<CombinedService>  $services
 * @return list<string>
 */
function orderedNames(BootCommandRegistry $registry, array $services): array
{
    return array_map(
        fn (CombinedService $service): string => $service->name,
        (new StreamOrder($registry))->sort($services),
    );
}

test('with nothing registered the canonical stream is untouched', function (): void {
    $services = [service('server'), service('queue'), service('horizon'), service('vite')];

    expect(orderedNames(orderRegistry(), $services))->toBe(['server', 'queue', 'horizon', 'vite']);
});

test('an unplaced registration follows the canonical services', function (): void {
    $registry = orderRegistry();
    $registry->register('stripe listen', source: RegistrationSource::Application);

    expect(orderedNames($registry, [service('server'), service('queue'), service('stripe')]))
        ->toBe(['server', 'queue', 'stripe']);
});

test('a registration replacing a built-in inherits its canonical slot', function (): void {
    $registry = orderRegistry();
    $registry->artisan('queue:listen', 'queue', RegistrationSource::Application);

    // The replacement launches last in the pipeline, so it queues last —
    // the canonical rank pulls it back into the queue slot.
    $services = [service('server'), service('horizon'), service('vite'), service('queue')];

    expect(orderedNames($registry, $services))->toBe(['server', 'queue', 'horizon', 'vite']);
});

test('first and last pull blocks to the edges in registration order', function (): void {
    $registry = orderRegistry();
    $registry->register('alpha cmd', source: RegistrationSource::Application)->first();
    $registry->register('bravo cmd', source: RegistrationSource::Application)->first();
    $registry->register('omega cmd', source: RegistrationSource::Application)->last();

    $services = [service('server'), service('queue'), service('omega'), service('alpha'), service('bravo')];

    expect(orderedNames($registry, $services))->toBe(['alpha', 'bravo', 'server', 'queue', 'omega']);
});

test('before and after place a registration relative to a stream', function (): void {
    $registry = orderRegistry();
    $registry->register('stripe listen', source: RegistrationSource::Application)->after('queue');
    $registry->register('ngrok http 80', source: RegistrationSource::Application)->before('server');

    $services = [service('server'), service('queue'), service('vite'), service('stripe'), service('ngrok')];

    expect(orderedNames($registry, $services))->toBe(['ngrok', 'server', 'queue', 'stripe', 'vite']);
});

test('several registrations after the same stream keep registration order', function (): void {
    $registry = orderRegistry();
    $registry->register('one cmd', source: RegistrationSource::Application)->after('queue');
    $registry->register('two cmd', source: RegistrationSource::Application)->after('queue');

    $services = [service('server'), service('queue'), service('vite'), service('one'), service('two')];

    expect(orderedNames($registry, $services))->toBe(['server', 'queue', 'one', 'two', 'vite']);
});

test('an unknown or absent target leaves the registration in place', function (): void {
    $registry = orderRegistry();
    $registry->register('stripe listen', source: RegistrationSource::Application)->after('nope');
    $registry->register('ngrok http 80', source: RegistrationSource::Application)->before('scheduler');

    // scheduler never queued (skipped built-in) and 'nope' never existed.
    $services = [service('server'), service('queue'), service('stripe'), service('ngrok')];

    expect(orderedNames($registry, $services))->toBe(['server', 'queue', 'stripe', 'ngrok']);
});

test('a placed registration missing from the stream is skipped', function (): void {
    $registry = orderRegistry();
    $registry->register('stripe listen', source: RegistrationSource::Application)->inBackground()->first();

    $services = [service('server'), service('queue')];

    expect(orderedNames($registry, $services))->toBe(['server', 'queue']);
});

test('apply returns a new ordered plan and leaves the original alone', function (): void {
    $registry = orderRegistry();
    $registry->register('stripe listen', source: RegistrationSource::Application)->first();

    $plan = new Igne\LaravelBootUp\Serve\CombinedRunPlan;
    $plan->add(service('server'));
    $plan->add(service('stripe'));

    $ordered = (new StreamOrder($registry))->apply($plan);

    expect(array_map(fn (CombinedService $service): string => $service->name, $ordered->services()))
        ->toBe(['stripe', 'server'])
        ->and(array_map(fn (CombinedService $service): string => $service->name, $plan->services()))
        ->toBe(['server', 'stripe']);
});
