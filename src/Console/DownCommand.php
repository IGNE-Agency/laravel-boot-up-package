<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Serve\ShutdownRunner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

final class DownCommand extends Command implements Isolatable
{
    protected $signature = 'app:down';

    protected $description = 'Stop tracked background processes and the server app:serve started';

    public function handle(ShutdownRunner $shutdown): int
    {
        $shutdown->run();

        return self::SUCCESS;
    }
}
