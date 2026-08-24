<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\ProjectReadiness;
use Igne\LaravelBootUp\Config\EnvironmentConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Frontend\PackageJson;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-readiness-'.bin2hex(random_bytes(4));
    mkdir($this->workDir.'/vendor', 0755, true);

    // The baseline is a project that is ready: a .env with a key, installed
    // Composer dependencies and nothing frontend to install.
    file_put_contents($this->workDir.'/.env', "APP_ENV=local\nAPP_KEY=base64:x\n");
    file_put_contents($this->workDir.'/vendor/autoload.php', '<?php');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

/**
 * @param  array<string, mixed>  $serverVars
 */
function readiness(string $dir, ?AssetMode $assets = null, array $serverVars = []): ProjectReadiness
{
    return new ProjectReadiness(
        envFile: new EnvFile($dir.'/.env', $dir.'/.env.example'),
        packageJson: new PackageJson($dir.'/package.json'),
        frontendConfig: new FrontendConfig(assets: $assets ?? AssetMode::Watch),
        environmentConfig: new EnvironmentConfig,
        basePath: $dir,
        serverVars: $serverVars,
    );
}

test('a set-up project has no problems', function (): void {
    expect(readiness($this->workDir)->problems(new BootOptions))->toBe([]);
});

test('a missing .env is the first thing reported', function (): void {
    unlink($this->workDir.'/.env');

    expect(readiness($this->workDir)->problems(new BootOptions))
        ->toBe(['There is no .env file.']);
});

test('an empty APP_KEY is reported once the .env exists', function (): void {
    file_put_contents($this->workDir.'/.env', "APP_ENV=local\nAPP_KEY=\n");

    expect(readiness($this->workDir)->problems(new BootOptions))
        ->toBe(['APP_KEY is not set in .env.']);
});

test('missing composer dependencies are reported', function (): void {
    unlink($this->workDir.'/vendor/autoload.php');

    expect(readiness($this->workDir)->problems(new BootOptions))
        ->toBe(['Composer dependencies are not installed.']);
});

test('an APP_ENV outside the allow-list names the allowed values', function (): void {
    file_put_contents($this->workDir.'/.env', "APP_ENV=production\nAPP_KEY=base64:x\n");

    expect(readiness($this->workDir)->problems(new BootOptions))
        ->toBe(['APP_ENV is [production]; boot-up only runs in: local, development.']);
});

test('a missing APP_ENV counts as local, so a fresh clone is not refused', function (): void {
    file_put_contents($this->workDir.'/.env', "APP_KEY=base64:x\n");

    expect(readiness($this->workDir)->problems(new BootOptions))->toBe([]);
});

test('an SSH session is refused', function (string $variable): void {
    $problems = readiness($this->workDir, serverVars: [$variable => '10.0.0.1 1 10.0.0.2 22'])
        ->problems(new BootOptions);

    expect($problems)->toBe(['This looks like a remote machine (SSH); boot-up is for local development.']);
})->with(['SSH_CLIENT', 'SSH_TTY', 'SSH_CONNECTION']);

test('node_modules is required when a dev script would be watched', function (): void {
    file_put_contents($this->workDir.'/package.json', json_encode(['scripts' => ['dev' => 'vite']]));

    expect(readiness($this->workDir)->problems(new BootOptions))
        ->toBe(['Frontend dependencies are not installed (there is no node_modules).']);
});

test('node_modules is not required for a project without a dev script', function (): void {
    file_put_contents($this->workDir.'/package.json', json_encode(['scripts' => ['build' => 'vite build']]));

    expect(readiness($this->workDir)->problems(new BootOptions))->toBe([]);
});

test('node_modules is not required when the run skips assets', function (): void {
    file_put_contents($this->workDir.'/package.json', json_encode(['scripts' => ['dev' => 'vite']]));

    expect(readiness($this->workDir)->problems(new BootOptions(withAssets: false)))->toBe([]);
});

test('node_modules is not required when assets are not watched', function (): void {
    file_put_contents($this->workDir.'/package.json', json_encode(['scripts' => ['dev' => 'vite']]));

    expect(readiness($this->workDir, assets: AssetMode::Build)->problems(new BootOptions))->toBe([])
        ->and(readiness($this->workDir, assets: AssetMode::Skip)->problems(new BootOptions))->toBe([]);
});

test('every problem is reported, in the order a person would fix them', function (): void {
    file_put_contents($this->workDir.'/.env', "APP_ENV=staging\n");
    unlink($this->workDir.'/vendor/autoload.php');
    file_put_contents($this->workDir.'/package.json', json_encode(['scripts' => ['dev' => 'vite']]));

    expect(readiness($this->workDir)->problems(new BootOptions))->toBe([
        'APP_ENV is [staging]; boot-up only runs in: local, development.',
        'APP_KEY is not set in .env.',
        'Composer dependencies are not installed.',
        'Frontend dependencies are not installed (there is no node_modules).',
    ]);
});
