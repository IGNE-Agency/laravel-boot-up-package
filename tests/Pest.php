<?php

declare(strict_types=1);

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
