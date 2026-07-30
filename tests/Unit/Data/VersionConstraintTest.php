<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\VersionConstraint;

test('wildcard detection', function (string $constraint, bool $isWildcard): void {
    expect(VersionConstraint::of($constraint)->isWildcard())->toBe($isWildcard);
})->with([
    'asterisk' => ['*', true],
    'empty string' => ['', true],
    'whitespace only' => ['   ', true],
    'caret constraint' => ['^8.3', false],
    'range constraint' => ['>=1.0', false],
]);

test('the wildcard named constructor is a wildcard', function (): void {
    expect(VersionConstraint::wildcard()->isWildcard())->toBeTrue()
        ->and(VersionConstraint::wildcard()->isSatisfiedBy('0.0.1'))->toBeTrue();
});

test('satisfaction matrix', function (string $constraint, string $version, bool $satisfied): void {
    expect(VersionConstraint::of($constraint)->isSatisfiedBy($version))->toBe($satisfied);
})->with([
    'asterisk matches anything' => ['*', '0.0.1', true],
    'empty matches anything' => ['', '10.4', true],
    '^8.3 rejects 8.2.0' => ['^8.3', '8.2.0', false],
    '^8.3 rejects short 8.2' => ['^8.3', '8.2', false],
    '^8.3 accepts 8.3.5' => ['^8.3', '8.3.5', true],
    '^8.3 accepts 8.4.1' => ['^8.3', '8.4.1', true],
    '^8.3 rejects 9.0.0' => ['^8.3', '9.0.0', false],
    'compound range accepts inside' => ['>=1.2 <2.0', '1.5.0', true],
    'compound range rejects outside' => ['>=1.2 <2.0', '2.1.0', false],
    'tilde accepts patch bump' => ['~1.2.3', '1.2.10', true],
    'tilde rejects minor bump' => ['~1.2.3', '1.3.0', false],
]);

test('unparseable versions never block the boot', function (string $version): void {
    expect(VersionConstraint::of('^8.3')->isSatisfiedBy($version))->toBeTrue();
})->with([
    'plain garbage' => 'not-a-version',
    'empty output' => '',
    'non-numeric output' => 'built from source (rev abc)',
]);

test('an unparseable constraint is treated as satisfied instead of throwing', function (): void {
    expect(VersionConstraint::of('!!!garbage!!!')->isSatisfiedBy('1.0.0'))->toBeTrue();
});
