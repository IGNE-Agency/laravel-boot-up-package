<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Servers\Herd\HerdSites;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-herd-sites-'.bin2hex(random_bytes(4));
    $this->sitesDir = $this->workDir.'/Sites';
    mkdir($this->sitesDir, 0755, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

test('linkedPath resolves a live link and nameFor finds it by project path', function (): void {
    mkdir($this->workDir.'/my-app');
    symlink($this->workDir.'/my-app', $this->sitesDir.'/dashboard');

    $sites = new HerdSites($this->sitesDir);

    expect($sites->linkedPath('dashboard'))->toBe(realpath($this->workDir.'/my-app'))
        ->and($sites->nameFor($this->workDir.'/my-app'))->toBe('dashboard')
        ->and($sites->links())->toBe(['dashboard' => realpath($this->workDir.'/my-app')]);
});

test('a dead link still reports its target but matches no project', function (): void {
    symlink($this->workDir.'/moved-away', $this->sitesDir.'/stale');
    mkdir($this->workDir.'/my-app');

    $sites = new HerdSites($this->sitesDir);

    expect($sites->linkedPath('stale'))->toBe($this->workDir.'/moved-away')
        ->and($sites->nameFor($this->workDir.'/my-app'))->toBeNull();
});

test('unknown names and a missing registry read as empty', function (): void {
    $sites = new HerdSites($this->sitesDir);
    $missing = new HerdSites($this->workDir.'/does-not-exist');

    expect($sites->linkedPath('nope'))->toBeNull()
        ->and($missing->links())->toBe([])
        ->and($missing->nameFor($this->workDir))->toBeNull();
});

test('regular files in the registry are ignored', function (): void {
    file_put_contents($this->sitesDir.'/not-a-link', 'noise');

    expect((new HerdSites($this->sitesDir))->links())->toBe([]);
});
