<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Console\Fixtures;

use Igne\LaravelBootUp\Console\BootUpCommand;

/**
 * Exercises the choose() naming convention: the flavor argument resolves
 * against flavorOptions().
 */
final class FlavorCommand extends BootUpCommand
{
    protected $signature = 'test:flavor {flavor?}';

    protected $description = 'Fixture for the PromptsForChoice convention';

    public function handle(): int
    {
        $flavor = $this->choose('flavor', 'Which flavor?', 'vanilla');

        terminal()->info("Chose {$flavor}.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    protected function flavorOptions(): array
    {
        return ['vanilla' => 'Vanilla', 'chocolate' => 'Chocolate'];
    }
}
