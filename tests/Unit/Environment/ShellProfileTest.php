<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Environment\ShellProfile;

beforeEach(function (): void {
    $this->home = sys_get_temp_dir().'/boot-up-profile-'.bin2hex(random_bytes(4));
    mkdir($this->home, 0755, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->home));
});

test('path resolves zsh to .zshrc and bash to .bashrc', function (): void {
    expect((new ShellProfile($this->home, '/bin/zsh'))->path())->toBe($this->home.'/.zshrc')
        ->and((new ShellProfile($this->home, 'zsh'))->path())->toBe($this->home.'/.zshrc')
        ->and((new ShellProfile($this->home, '/opt/homebrew/bin/bash'))->path())->toBe($this->home.'/.bashrc');
});

test('path is null for unsupported shells', function (): void {
    expect((new ShellProfile($this->home, '/usr/bin/fish'))->path())->toBeNull();
});

test('constructor falls back to the HOME and SHELL environment variables', function (): void {
    $originalHome = getenv('HOME');
    $originalShell = getenv('SHELL');

    putenv('HOME='.$this->home);
    putenv('SHELL=/bin/bash');

    try {
        expect((new ShellProfile)->path())->toBe($this->home.'/.bashrc');
    } finally {
        putenv($originalHome === false ? 'HOME' : 'HOME='.$originalHome);
        putenv($originalShell === false ? 'SHELL' : 'SHELL='.$originalShell);
    }
});

test('exists reflects the presence of the profile file', function (): void {
    $profile = new ShellProfile($this->home, '/bin/zsh');

    expect($profile->exists())->toBeFalse();

    file_put_contents($this->home.'/.zshrc', "export PATH=\$PATH\n");

    expect($profile->exists())->toBeTrue();
});

test('exists is false when the shell is unsupported', function (): void {
    expect((new ShellProfile($this->home, '/usr/bin/fish'))->exists())->toBeFalse();
});

test('contains searches the profile content', function (): void {
    file_put_contents($this->home.'/.zshrc', "# my profile\nexport EDITOR=vim\n");

    $profile = new ShellProfile($this->home, '/bin/zsh');

    expect($profile->contains('EDITOR=vim'))->toBeTrue()
        ->and($profile->contains('sail'))->toBeFalse();
});

test('definesAlias matches alias definitions, including indented ones', function (): void {
    file_put_contents($this->home.'/.zshrc', implode("\n", [
        "alias sail='./vendor/bin/sail'",
        '  alias art="php artisan"',
    ])."\n");

    $profile = new ShellProfile($this->home, '/bin/zsh');

    expect($profile->definesAlias('sail'))->toBeTrue()
        ->and($profile->definesAlias('art'))->toBeTrue();
});

test('definesAlias ignores commented aliases and prefix collisions', function (): void {
    file_put_contents($this->home.'/.zshrc', implode("\n", [
        "# alias sail='./vendor/bin/sail'",
        "alias sailing='boats'",
    ])."\n");

    $profile = new ShellProfile($this->home, '/bin/zsh');

    expect($profile->definesAlias('sail'))->toBeFalse();
});

test('appendBlock creates the profile with marker lines when missing', function (): void {
    $profile = new ShellProfile($this->home, '/bin/zsh');

    $profile->appendBlock("alias sail='./vendor/bin/sail'");

    $content = (string) file_get_contents($this->home.'/.zshrc');

    expect($content)->toBe(
        "# >>> laravel-boot-up >>>\nalias sail='./vendor/bin/sail'\n# <<< laravel-boot-up <<<\n",
    )->and($profile->definesAlias('sail'))->toBeTrue();
});

test('appendBlock preserves existing content, even without a trailing newline', function (): void {
    file_put_contents($this->home.'/.zshrc', 'export EDITOR=vim');

    (new ShellProfile($this->home, '/bin/zsh'))->appendBlock("alias sail='./vendor/bin/sail'");

    expect((string) file_get_contents($this->home.'/.zshrc'))->toBe(implode("\n", [
        'export EDITOR=vim',
        '',
        '# >>> laravel-boot-up >>>',
        "alias sail='./vendor/bin/sail'",
        '# <<< laravel-boot-up <<<',
    ])."\n");
});

test('appendBlock is a no-op for unsupported shells', function (): void {
    (new ShellProfile($this->home, '/usr/bin/fish'))->appendBlock('alias sail=x');

    expect(array_diff(scandir($this->home) ?: [], ['.', '..']))->toBe([]);
});
