<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests;

use Closure;
use Laravel\Prompts\Prompt;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetPromptStatics();
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Igne\LaravelBootUp\BootUpServiceProvider::class,
        ];
    }

    /**
     * Artisan command tests flip laravel/prompts into fallback mode globally
     * and register fallback closures bound to that test's mocked output.
     * Both are static, so they leak into later tests and break Prompt::fake().
     */
    private function resetPromptStatics(): void
    {
        Closure::bind(function (): void {
            self::$shouldFallback = false;
            self::$fallbacks = [];
        }, null, Prompt::class)();
    }
}
