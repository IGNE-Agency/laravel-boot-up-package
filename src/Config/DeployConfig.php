<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Concerns\ValidatesConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Illuminate\Contracts\Config\Repository;

final readonly class DeployConfig
{
    use ValidatesConfig;

    /**
     * @param  list<string>  $finalize  artisan commands run at the end of a deploy
     * @param  array<string, class-string>  $scriptGenerators  platform key => ScriptGenerator class; wins over built-ins
     * @param  list<string>  $steps  the app:deploy pipeline; [] here because the
     *                               canonical list lives in the published config file
     */
    public function __construct(
        public bool $cacheFrameworkFiles = false,
        public array $finalize = ['storage:link'],
        public array $scriptGenerators = [],
        public array $steps = [],
        public bool $autoAccept = false,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            cacheFrameworkFiles: (bool) $config->get('boot-up.deploy.cache_framework_files', false),
            finalize: (array) $config->get('boot-up.deploy.finalize', ['storage:link']),
            scriptGenerators: (array) $config->get('boot-up.deploy.script_generators', []),
            steps: self::validatedSteps((array) $config->get('boot-up.deploy.steps', []), 'boot-up.deploy.steps', Step::class),
            autoAccept: (bool) $config->get('boot-up.deploy.auto_accept', false),
        );
    }
}
