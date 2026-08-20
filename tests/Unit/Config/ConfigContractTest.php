<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Contracts\Step;
use Illuminate\Config\Repository;

/**
 * Every config class must satisfy the same three-way agreement: constructor
 * defaults, fromRepository() fallbacks and the published config file all
 * describe the SAME configuration. These tests are globbed so a new config
 * class is covered the moment it exists.
 */

/** @return list<class-string> */
function configClasses(): array
{
    return collect(glob(dirname(__DIR__, 3).'/src/Config/*Config.php'))
        ->map(fn (string $path): string => 'Igne\\LaravelBootUp\\Config\\'.basename($path, '.php'))
        ->values()
        ->all();
}

/** @return array<string, mixed> */
function publishedConfig(): array
{
    return require dirname(__DIR__, 3).'/config/boot-up.php';
}

function bootUpEnvIsSet(): bool
{
    return collect(array_keys(getenv()))
        ->merge(array_keys($_ENV))
        ->contains(fn (string $key): bool => str_starts_with($key, 'BOOT_UP_'));
}

/**
 * Properties whose published value is legitimately not the constructor
 * default: 'drivers' merges under array_merge (the published map is not
 * authoritative), and the two step lists deliberately default to [] so the
 * canonical lists live only in the published file.
 */
const PUBLISHED_EXEMPT_PROPERTIES = ['drivers', 'steps'];

test('fromRepository on an empty repository equals the constructor defaults', function (): void {
    foreach (configClasses() as $class) {
        expect($class::fromRepository(new Repository([])))
            ->toEqual(new $class, "{$class} drifts between its get() fallbacks and its constructor defaults");
    }
});

test('the published config file agrees with the constructor defaults', function (): void {
    $fromPublished = fn (string $class): object => $class::fromRepository(
        new Repository(['boot-up' => publishedConfig()]),
    );

    foreach (configClasses() as $class) {
        $published = $fromPublished($class);
        $defaults = new $class;

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (\in_array($property->getName(), PUBLISHED_EXEMPT_PROPERTIES, true)) {
                continue;
            }

            expect($property->getValue($published))->toEqual(
                $property->getValue($defaults),
                "{$class}::\${$property->getName()} differs between the published file and the constructor default",
            );
        }
    }
})->skip(bootUpEnvIsSet(), 'BOOT_UP_* environment variables override published values');

test('every leaf in the published config file is read by some config class', function (): void {
    $spy = new class(['boot-up' => publishedConfig()]) extends Repository
    {
        /** @var list<string> */
        public array $reads = [];

        public function get($key, $default = null): mixed
        {
            \is_string($key) && $this->reads[] = $key;

            return parent::get($key, $default);
        }
    };

    foreach (configClasses() as $class) {
        $class::fromRepository($spy);
    }

    $leaves = [];
    $flatten = function (array $values, string $prefix) use (&$flatten, &$leaves): void {
        foreach ($values as $key => $value) {
            $path = "{$prefix}.{$key}";
            \is_array($value) && $value !== []
                ? $flatten($value, $path)
                : $leaves[] = $path;
        }
    };
    $flatten(publishedConfig(), 'boot-up');

    foreach ($leaves as $leaf) {
        $covered = collect($spy->reads)->contains(
            fn (string $read): bool => $leaf === $read || str_starts_with($leaf, "{$read}."),
        );

        expect($covered)->toBeTrue("published key [{$leaf}] is read by no config class");
    }
});

test('the published step lists are non-empty and hold only pipeline steps', function (): void {
    $published = publishedConfig();

    foreach (['dev', 'deploy'] as $command) {
        $steps = $published[$command]['steps'];

        expect($steps)->not->toBeEmpty();

        foreach ($steps as $step) {
            [$class] = explode(':', (string) $step, 2);

            expect(class_exists($class))->toBeTrue("{$command} step [{$class}] does not exist")
                ->and(is_a($class, Step::class, true))->toBeTrue("{$command} step [{$class}] does not implement Step");
        }
    }
});

test('dev and deploy auto_accept are independent keys', function (): void {
    $repository = new Repository(['boot-up' => [
        'dev' => ['auto_accept' => true],
        'deploy' => ['auto_accept' => false],
    ]]);

    expect(Igne\LaravelBootUp\Config\DevConfig::fromRepository($repository)->autoAccept)->toBeTrue()
        ->and(Igne\LaravelBootUp\Config\DeployConfig::fromRepository($repository)->autoAccept)->toBeFalse();
});

test('the provider registers every config class exactly once', function (): void {
    $constant = new ReflectionClassConstant(
        Igne\LaravelBootUp\Providers\BootUpServiceProvider::class,
        'CONFIG_CLASSES',
    );

    $registered = $constant->getValue();
    $counts = array_count_values($registered);

    expect(array_keys($counts))->toEqualCanonicalizing(configClasses());

    foreach ($counts as $class => $times) {
        expect($times)->toBe(1, "{$class} is registered {$times} times");
    }
});
