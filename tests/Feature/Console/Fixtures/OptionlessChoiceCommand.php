<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Console\Fixtures;

use Igne\LaravelBootUp\Console\BootUpCommand;

/**
 * Calls choose() without defining the flavorOptions() method the
 * convention demands — the developer-error path.
 */
final class OptionlessChoiceCommand extends BootUpCommand
{
    protected $signature = 'test:optionless {flavor?}';

    protected $description = 'Fixture for a choose() call missing its options method';

    public function handle(): int
    {
        $this->choose('flavor', 'Which flavor?');

        return self::SUCCESS;
    }
}
