<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Facades\Terminal;

if (! function_exists('terminal')) {
    /**
     * The package's terminal — resolves the same singleton the
     * Igne\LaravelBootUp\Facades\Terminal facade fronts, without needing
     * an import: terminal()->info('...').
     */
    function terminal(): Igne\LaravelBootUp\Services\Terminal
    {
        return Terminal::getFacadeRoot();
    }
}
