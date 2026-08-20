<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Laravel\Prompts\Prompt;

function selectorFor(string $dir, ?PackageManager $configured = null): PackageManagerSelector
{
    return new PackageManagerSelector(
        new FrontendConfig($configured),
        new PackageJson($dir.'/package.json'),
    );
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-pm-selector-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('a please-use sentinel beats the configured manager, with a warning', function (): void {
    file_put_contents($this->dir.'/package.json', json_encode(['engines' => ['node' => 'please-use-pnpm']]));
    file_put_contents($this->dir.'/yarn.lock', '');

    expect(selectorFor($this->dir, PackageManager::Bun)->selected())->toBe(PackageManager::Pnpm);
    Prompt::assertStrippedOutputContains('package.json demands pnpm; using it instead of the configured bun.');
});

test('a please-use sentinel without explicit config wins silently', function (): void {
    file_put_contents($this->dir.'/package.json', json_encode(['engines' => ['node' => 'please-use-yarn']]));

    expect(selectorFor($this->dir)->selected())->toBe(PackageManager::Yarn);
    Prompt::assertStrippedOutputDoesntContain('demands');
});

test('an explicit config value beats the lockfile', function (): void {
    file_put_contents($this->dir.'/package.json', '{}');
    file_put_contents($this->dir.'/pnpm-lock.yaml', '');

    expect(selectorFor($this->dir, PackageManager::Npm)->selected())->toBe(PackageManager::Npm);
});

test('without config the lockfile decides, with a note', function (): void {
    file_put_contents($this->dir.'/package.json', '{}');
    file_put_contents($this->dir.'/pnpm-lock.yaml', '');

    expect(selectorFor($this->dir)->selected())->toBe(PackageManager::Pnpm);
    Prompt::assertStrippedOutputContains('Using pnpm — detected from pnpm-lock.yaml.');
});

test('no signal at all falls back to the default', function (): void {
    expect(selectorFor($this->dir)->selected())->toBe(PackageManager::default());
});

test('a binary bun lockfile is recognised, and named as the file that was found', function (): void {
    file_put_contents($this->dir.'/package.json', '{}');
    file_put_contents($this->dir.'/bun.lockb', '');

    $selector = new PackageManagerSelector(new FrontendConfig, new PackageJson($this->dir.'/package.json'));

    expect($selector->selected())->toBe(PackageManager::Bun);
    Prompt::assertStrippedOutputContains('detected from bun.lockb');
});

test('the text bun lockfile wins when a project has both', function (): void {
    file_put_contents($this->dir.'/package.json', '{}');
    file_put_contents($this->dir.'/bun.lock', '');
    file_put_contents($this->dir.'/bun.lockb', '');

    $selector = new PackageManagerSelector(new FrontendConfig, new PackageJson($this->dir.'/package.json'));

    expect($selector->selected())->toBe(PackageManager::Bun);
    Prompt::assertStrippedOutputContains('detected from bun.lock.');
});
