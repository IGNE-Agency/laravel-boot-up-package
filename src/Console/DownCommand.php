<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Serve\ShutdownRunner;
use Illuminate\Contracts\Console\Isolatable;

final class DownCommand extends BootUpCommand implements Isolatable
{
    protected $signature = 'app:down';

    protected $description = 'Stop tracked background processes and the server app:serve started';

    public function perform(ShutdownRunner $shutdown): int
    {
        terminal()->intro('Stopping everything boot-up started...');

        $shutdown->run();

        terminal()->outro('Done.');

        return self::SUCCESS;
    }
}
