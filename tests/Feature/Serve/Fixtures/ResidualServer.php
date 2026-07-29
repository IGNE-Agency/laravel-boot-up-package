<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Serve\Fixtures;

use Igne\LaravelBootUp\Contracts\HasResidualState;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\ServeContext;

/**
 * A not-running driver double that can report residual state, for the
 * shutdown cleanup-offer tests. Register the instance with
 * app()->instance(ResidualServer::class, $double).
 */
final class ResidualServer implements HasResidualState, Server
{
    public int $cleanUps = 0;

    public function __construct(
        public bool $residualState = true,
        public bool $cleanUpThrows = false,
    ) {}

    public function key(): string
    {
        return 'residual';
    }

    public function label(): string
    {
        return 'Residual Server';
    }

    public function isRunning(): bool
    {
        return false;
    }

    public function start(ServeContext $context): void {}

    public function stop(): void {}

    public function url(): string
    {
        return 'http://residual.test';
    }

    public function hasResidualState(): bool
    {
        return $this->residualState;
    }

    public function residualStateImpact(): string
    {
        return 'A failed boot can leave leftovers behind; cleanup removes them.';
    }

    public function cleanUpResidualState(): void
    {
        $this->cleanUps++;

        if ($this->cleanUpThrows) {
            throw new \RuntimeException('cleanup failed');
        }

        $this->residualState = false;
    }
}
