<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\EnvRestorePoint;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Tests\TestCase;

uses(TestCase::class)->in('Feature');

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
