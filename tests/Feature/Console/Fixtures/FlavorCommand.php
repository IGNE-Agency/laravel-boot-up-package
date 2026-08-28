<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Console\Fixtures;

use Igne\LaravelBootUp\Console\BootUpCommand;

/**
 * Exercises choose(): the flavor argument resolves against the passed
 * options.
 */
final class FlavorCommand extends BootUpCommand
{
    protected $signature = 'test:flavor {flavor?}';

    protected $description = 'Fixture for the PromptsForChoice flow';

    public function handle(): int
    {
        $flavor = $this->choose('flavor', 'Which flavor?', $this->flavorOptions(), 'vanilla');

        terminal()->info("Chose {$flavor}.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function flavorOptions(): array
    {
        return ['vanilla' => 'Vanilla', 'chocolate' => 'Chocolate'];
    }
}
