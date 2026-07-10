<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Tests\Feature\Tools\Fixtures;

final class DockerToolSpy extends EnsureToolsReadySpy
{
    public function id(): string
    {
        return 'docker';
    }
}
