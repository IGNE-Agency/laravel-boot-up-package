<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Deploy;

use Illuminate\Contracts\Config\Repository;

final readonly class DeployConfig
{
    /**
     * @param  list<string>  $finalize  artisan commands run at the end of a deploy
     * @param  array<string, class-string>  $scriptGenerators  platform key => ScriptGenerator class; wins over built-ins
     */
    public function __construct(
        public bool $cacheFrameworkFiles,
        public array $finalize,
        public array $scriptGenerators = [],
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            cacheFrameworkFiles: (bool) $config->get('bootstrap.deploy.cache_framework_files', false),
            finalize: (array) $config->get('bootstrap.deploy.finalize', ['storage:link']),
            scriptGenerators: (array) $config->get('bootstrap.deploy.script_generators', []),
        );
    }
}
