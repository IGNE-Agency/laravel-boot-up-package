<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Tools\Fixtures;

final class AlphaToolSpy extends EnsureToolsReadySpy
{
    public function id(): string
    {
        return 'alpha';
    }
}
