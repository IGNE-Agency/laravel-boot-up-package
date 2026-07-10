<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Environment\EnvironmentConfig;
use Igne\LaravelBootstrap\Environment\ShellProfile;
use Igne\LaravelBootstrap\Servers\Sail\SailAliasInstaller;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->home = sys_get_temp_dir().'/bootstrap-sail-alias-'.bin2hex(random_bytes(4));
    mkdir($this->home, 0755, true);
    $this->profilePath = $this->home.'/.zshrc';
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->home));
});

function sailAliasInstaller(string $home, bool $manage = true): SailAliasInstaller
{
    return new SailAliasInstaller(
        new ShellProfile($home, '/bin/zsh'),
        new EnvironmentConfig(manageSailAlias: $manage),
    );
}

test('does nothing when alias management is disabled', function (): void {
    file_put_contents($this->profilePath, "# mine\n");

    sailAliasInstaller($this->home, manage: false)->ensure();

    expect((string) file_get_contents($this->profilePath))->toBe("# mine\n");
});

test('does nothing when the profile does not exist', function (): void {
    sailAliasInstaller($this->home)->ensure();

    expect(is_file($this->profilePath))->toBeFalse();
});

test('does nothing when a sail alias is already defined', function (): void {
    file_put_contents($this->profilePath, "alias sail='vendor/bin/sail'\n");

    sailAliasInstaller($this->home)->ensure();

    expect((string) file_get_contents($this->profilePath))->toBe("alias sail='vendor/bin/sail'\n");
});

test('appends the alias block when the user confirms', function (): void {
    file_put_contents($this->profilePath, "# mine\n");
    Prompt::fake([Key::ENTER]);

    sailAliasInstaller($this->home)->ensure();

    $profile = (string) file_get_contents($this->profilePath);

    expect($profile)->toContain("alias sail='[ -f sail ] && bash sail || bash vendor/bin/sail'")
        ->and($profile)->toContain('# >>> laravel-bootstrap >>>')
        ->and($profile)->toContain('# mine');
    Prompt::assertStrippedOutputContains('source '.$this->profilePath);
});

test('leaves the profile alone when the user declines', function (): void {
    file_put_contents($this->profilePath, "# mine\n");
    Prompt::fake(['n', Key::ENTER]);

    sailAliasInstaller($this->home)->ensure();

    expect((string) file_get_contents($this->profilePath))->toBe("# mine\n");
    Prompt::assertStrippedOutputContains('you can add it yourself');
});
