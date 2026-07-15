<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy;

enum ProjectCommandType: string
{
    case ARTISAN = 'artisan';
    case COMPOSER = 'composer';
    case PACKAGE_MANAGER = 'package_manager';
}
