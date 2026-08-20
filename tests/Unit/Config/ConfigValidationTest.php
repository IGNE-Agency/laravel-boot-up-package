<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ArtisanServeConfig;
use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Config\DevConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Config\HerdConfig;
use Igne\LaravelBootUp\Config\SailConfig;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Exceptions\ConfigException;
use Illuminate\Config\Repository;

/**
 * Every message names the key it came from: a misconfiguration the user cannot
 * locate is barely better than one that fails silently.
 */
function devConfigWith(mixed $steps): Repository
{
    return new Repository(['boot-up' => ['dev' => ['steps' => $steps]]]);
}

test('a step class that does not exist is rejected before the boot starts', function (): void {
    expect(fn () => DevConfig::fromRepository(devConfigWith(['App\\Steps\\Nope'])))
        ->toThrow(ConfigException::class, 'boot-up.dev.steps');
});

test('a step class that is not a Step is rejected', function (): void {
    expect(fn () => DevConfig::fromRepository(devConfigWith([stdClass::class])))
        ->toThrow(ConfigException::class, 'Igne\\LaravelBootUp\\Contracts\\Step');
});

test('a step entry is validated without its variant argument getting in the way', function (): void {
    expect(fn () => DevConfig::fromRepository(devConfigWith(['App\\Steps\\Nope:before'])))
        ->toThrow(ConfigException::class, 'App\\Steps\\Nope');
});

test('the deploy pipeline is validated the same way', function (): void {
    $config = new Repository(['boot-up' => ['deploy' => ['steps' => [stdClass::class]]]]);

    expect(fn () => DeployConfig::fromRepository($config))
        ->toThrow(ConfigException::class, 'boot-up.deploy.steps');
});

test('a herd attempt count of zero is rejected, because zero attempts never probes', function (): void {
    $config = new Repository(['boot-up' => ['herd' => ['health' => ['attempts' => 0]]]]);

    expect(fn () => HerdConfig::fromRepository($config))
        ->toThrow(ConfigException::class, 'boot-up.herd.health.attempts');
});

test('a negative herd delay is rejected, because usleep would throw mid-boot', function (): void {
    $config = new Repository(['boot-up' => ['herd' => ['health' => ['delay_ms' => -1]]]]);

    expect(fn () => HerdConfig::fromRepository($config))
        ->toThrow(ConfigException::class, 'boot-up.herd.health.delay_ms');
});

test('a zero herd delay is allowed — no wait between probes is a choice', function (): void {
    $config = new Repository(['boot-up' => ['herd' => ['health' => ['delay_ms' => 0]]]]);

    expect(HerdConfig::fromRepository($config)->healthDelayMs)->toBe(0);
});

test('a sail timeout of zero is rejected, because it would poll exactly once', function (): void {
    $config = new Repository(['boot-up' => ['sail' => ['ready_timeout_seconds' => 0]]]);

    expect(fn () => SailConfig::fromRepository($config))
        ->toThrow(ConfigException::class, 'boot-up.sail.ready_timeout_seconds');
});

test('a port outside the legal range is rejected', function (): void {
    $config = new Repository(['boot-up' => ['artisan' => ['port' => 70000]]]);

    expect(fn () => ArtisanServeConfig::fromRepository($config))
        ->toThrow(ConfigException::class, 'boot-up.artisan.port');
});

test('an array where an enum was expected names the key instead of warning', function (): void {
    $config = new Repository(['boot-up' => ['frontend' => ['assets' => ['watch']]]]);

    expect(fn () => FrontendConfig::fromRepository($config))
        ->toThrow(ConfigException::class, 'boot-up.frontend.assets');
});

test('BOOT_UP_ASSETS=false reaches the enum as a bool and is named as one', function (): void {
    // env() casts the string "false" to a bool, which used to cast to '' and
    // report the nonsense "unknown value []".
    $config = new Repository(['boot-up' => ['frontend' => ['assets' => false]]]);

    expect(fn () => FrontendConfig::fromRepository($config))
        ->toThrow(ConfigException::class, 'is bool');
});

test('an unset enum still means the documented default', function (): void {
    foreach ([null, ''] as $unset) {
        $config = new Repository(['boot-up' => ['frontend' => ['assets' => $unset]]]);

        expect(FrontendConfig::fromRepository($config)->assets)->toBe(AssetMode::default());
    }
});
