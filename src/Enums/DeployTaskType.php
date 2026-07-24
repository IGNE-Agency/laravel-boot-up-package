<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

enum DeployTaskType: string
{
    case ARTISAN = 'artisan';
    case COMPOSER = 'composer';
    case PACKAGE_MANAGER = 'package_manager';
}
