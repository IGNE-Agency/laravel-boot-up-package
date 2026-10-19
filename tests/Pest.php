<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\EnvRestorePoint;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Tests\TestCase;
use Illuminate\Support\Facades\Process;

uses(TestCase::class)->in('Feature');

/**
 * The environment git runs with in tests: no global or system config, so a
 * developer's own core.hooksPath (or any other setting) cannot leak into a
 * throwaway repository. Merge it into $_ENV for git the package spawns.
 *
 * @return array<string, string>
 */
function isolatedGitEnvironment(): array
{
    return ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'];
}

/**
 * A fresh git repository in a throwaway directory — the caller deletes it.
 */
function gitRepository(): string
{
    $directory = sys_get_temp_dir().'/boot-up-git-'.bin2hex(random_bytes(4));
    mkdir($directory, 0755, true);

    runGit($directory, 'init', '--quiet');

    return (string) realpath($directory);
}

/**
 * Run git in the given directory with the isolated config; throws on failure.
 */
function runGit(string $directory, string ...$arguments): string
{
    return Process::path($directory)
        ->env(isolatedGitEnvironment())
        ->run(['git', ...$arguments])
        ->throw()
        ->output();
}

/**
 * A throwaway active-server record for tests that construct a ServerSelector
 * without caring what the last setup chose.
 */
function activeServerStore(): ActiveServerStore
{
    return new ActiveServerStore(sys_get_temp_dir().'/boot-up-selector-'.bin2hex(random_bytes(4)).'.json');
}

/**
 * An .env restore point over the given directory — for the many collaborators
 * that take one without the test caring what it records. Pass the same
 * directory as the EnvFile under test to assert on the undo itself.
 */
function envRestorePoint(?string $directory = null): EnvRestorePoint
{
    $directory ??= sys_get_temp_dir().'/boot-up-env-restore-'.bin2hex(random_bytes(4));

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    return new EnvRestorePoint(
        new EnvFile($directory.'/.env', $directory.'/.env.example'),
        $directory.'/env-restore.json',
    );
}
