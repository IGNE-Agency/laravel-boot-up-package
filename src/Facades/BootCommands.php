<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Facades;

use Igne\LaravelBootUp\Enums\RegistrationSource;
use Igne\LaravelBootUp\Serve\BootCommandRegistry;
use Igne\LaravelBootUp\Serve\PendingBootProcess;
use Illuminate\Support\Facades\Facade;

/**
 * Register extra dev processes for app:serve from a service provider's
 * boot() — the package's take on Laravel's DevCommands:
 *
 *     BootCommands::artisan('reverb:start', 'reverb')->orange();
 *     BootCommands::register('stripe listen --forward-to '.config('app.url'));
 *     BootCommands::packageManager('run dev')->after('queue');
 *     BootCommands::except('scheduler');
 *
 * @method static PendingBootProcess register(string $command, ?string $name = null, ?RegistrationSource $source = null)
 * @method static PendingBootProcess artisan(string $command, ?string $name = null, ?RegistrationSource $source = null)
 * @method static PendingBootProcess packageManager(string $command, ?string $name = null, ?RegistrationSource $source = null)
 * @method static PendingBootProcess packageManagerExec(string $command, ?string $name = null, ?RegistrationSource $source = null)
 * @method static void only(string ...$streamNames)
 * @method static void except(string ...$streamNames)
 *
 * @see BootCommandRegistry
 */
final class BootCommands extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BootCommandRegistry::class;
    }
}
