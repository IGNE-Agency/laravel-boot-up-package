<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\GeneratedFile;
use Igne\LaravelBootUp\Services\GeneratedFilePublisher;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-publisher-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('a file in a new directory is written without a gitignore that would hide it', function (): void {
    // Generated files are committed; AtomicFile's catch-all .gitignore is
    // for storage state and would keep them out of the repository.
    (new GeneratedFilePublisher($this->dir))->publish([
        new GeneratedFile('.fortrabbit/deploy.yml', "apiVersion: 1.0\n"),
        new GeneratedFile('scripts/ci/bootstrap.sh', "#!/usr/bin/env bash\n", executable: true),
    ]);

    expect(file_get_contents($this->dir.'/.fortrabbit/deploy.yml'))->toBe("apiVersion: 1.0\n")
        ->and(is_executable($this->dir.'/scripts/ci/bootstrap.sh'))->toBeTrue()
        ->and(is_file($this->dir.'/.fortrabbit/.gitignore'))->toBeFalse()
        ->and(is_file($this->dir.'/scripts/.gitignore'))->toBeFalse()
        ->and(is_file($this->dir.'/scripts/ci/.gitignore'))->toBeFalse();
});
