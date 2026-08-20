<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

enum DeployTaskType: string
{
    case Artisan = 'artisan';
    case Composer = 'composer';
    case PackageManager = 'package_manager';
}
