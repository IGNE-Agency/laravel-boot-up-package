<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests;

use Closure;
use Illuminate\Foundation\DevCommandMode;
use Illuminate\Foundation\DevCommands;
use Laravel\Prompts\Prompt;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        // Before the app boots: booting re-runs DevCommands::registerDefaults(),
        // so this clears the previous test's registrations while leaving each
        // test with the same defaults a real application starts from.
        $this->resetDevCommandsStatics();

        parent::setUp();

        $this->resetPromptStatics();
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Igne\LaravelBootUp\Providers\BootUpServiceProvider::class,
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

    /**
     * DevCommands keeps every registration, filter and display setting in static
     * properties with no reset hook, so one test's processes surface in the next.
     * Assigning undeclared properties is a fatal error, which is deliberate: if
     * the framework renames one, it fails here instead of leaking silently.
     */
    private function resetDevCommandsStatics(): void
    {
        Closure::bind(function (): void {
            self::$commands = [];
            self::$only = [];
            self::$except = [];
            self::$colorCount = 0;
            self::$packageManager = null;
            self::$mode = DevCommandMode::TABS;
            self::$withTimestamps = false;
            self::$autoRestart = true;
            self::$bufferSize = null;
            self::$streamBufferSize = null;
        }, null, DevCommands::class)();
    }
}
