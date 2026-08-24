<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Boot\ShutdownRunner;
use Illuminate\Contracts\Console\Isolatable;

final class DownCommand extends BootUpCommand implements Isolatable
{
    protected $signature = 'app:down';

    protected $description = 'Stop tracked background processes and the server that php artisan app:setup started';

    /**
     * Teardown signals processes and reads the process table, so it needs a
     * Unix-like environment just as much as the boot does.
     */
    protected function requiresUnix(): bool
    {
        return true;
    }

    public function handle(ShutdownRunner $shutdown): int
    {
        $this->announce('Stopping everything boot-up started...');

        $shutdown->run();

        return $this->done('Done.');
    }
}
