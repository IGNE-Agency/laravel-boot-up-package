<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Pipelines\ComposerJson;

function composerJsonFixture(array $data): string
{
    $path = sys_get_temp_dir().'/boot-up-composer-'.bin2hex(random_bytes(4)).'.json';

    file_put_contents($path, json_encode($data));

    return $path;
}

test('a missing composer.json yields the documented defaults', function (): void {
    $composer = new ComposerJson(sys_get_temp_dir().'/boot-up-missing-'.bin2hex(random_bytes(4)).'.json');

    expect($composer->exists())->toBeFalse()
        ->and($composer->read())->toBe([])
        ->and($composer->requires('laravel/nova'))->toBeFalse()
        ->and($composer->phpVersion())->toBe('8.4');
});

test('invalid json reads as an empty document', function (): void {
    $path = composerJsonFixture([]);
    file_put_contents($path, '{not json');

    expect((new ComposerJson($path))->read())->toBe([]);

    @unlink($path);
});

test('the php version is the first major.minor in the require constraint', function (string $constraint, string $version): void {
    $path = composerJsonFixture(['require' => ['php' => $constraint]]);

    expect((new ComposerJson($path))->phpVersion())->toBe($version);

    @unlink($path);
})->with([
    'caret' => ['^8.3', '8.3'],
    'wildcard' => ['8.4.*', '8.4'],
    'range' => ['>=8.2 <8.5', '8.2'],
]);

test('a require section without php falls back to the default', function (): void {
    $path = composerJsonFixture(['require' => ['laravel/framework' => '^13.0']]);

    expect((new ComposerJson($path))->phpVersion('8.3'))->toBe('8.3');

    @unlink($path);
});

test('requires sees production dependencies but not dev dependencies', function (): void {
    $path = composerJsonFixture([
        'require' => ['laravel/nova' => '^5.0'],
        'require-dev' => ['pestphp/pest' => '^4.0'],
    ]);

    $composer = new ComposerJson($path);

    expect($composer->requires('laravel/nova'))->toBeTrue()
        ->and($composer->requires('pestphp/pest'))->toBeFalse();

    @unlink($path);
});

test('requiresDev sees dev dependencies but not production dependencies', function (): void {
    $path = composerJsonFixture([
        'require' => ['laravel/nova' => '^5.0'],
        'require-dev' => ['laravel/pint' => '^1.27'],
    ]);

    $composer = new ComposerJson($path);

    expect($composer->requiresDev('laravel/pint'))->toBeTrue()
        ->and($composer->requiresDev('laravel/nova'))->toBeFalse();

    @unlink($path);
});
