<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Concerns\ValidatesConfig;
use Igne\LaravelBootUp\Enums\FortrabbitDeployStrategy;
use Igne\LaravelBootUp\Exceptions\ConfigException;
use Illuminate\Contracts\Config\Repository;

/**
 * The fortrabbit deployment settings besides the two command lists — what
 * its dashboard offers and .fortrabbit/deploy.yml accepts. A deploy file
 * replaces the dashboard's settings wholesale and an omitted key falls back
 * to fortrabbit's default, so the defaults restate its Laravel template:
 * without the /storage exclude, a replace deploy wipes persistent storage.
 */
final readonly class FortrabbitConfig
{
    use ValidatesConfig;

    private const string KEY = 'boot-up.deploy.fortrabbit';

    /**
     * @param  list<string>  $cachedDirectories  carried from one deployment to the next
     * @param  list<string>  $excludePatterns  left untouched on the environment by a deploy
     * @param  list<string>  $replacePatterns  deleted before a merge deploy — merge strategy only
     * @param  string|null  $gitSourceDirectory  the repository subdirectory to deploy; null deploys the root
     */
    public function __construct(
        public array $cachedDirectories = ['vendor', 'node_modules', '.pnpm-store'],
        public FortrabbitDeployStrategy $strategy = FortrabbitDeployStrategy::Replace,
        public array $excludePatterns = ['/storage', '/node_modules'],
        public array $replacePatterns = [],
        public ?string $gitSourceDirectory = null,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        $strategy = FortrabbitDeployStrategy::fromConfig($config->get(self::KEY.'.strategy'), self::KEY.'.strategy');
        $replacePatterns = self::stringListFrom($config, self::KEY.'.replace_patterns', []);

        // fortrabbit rejects the whole deploy file for this, after the push.
        if ($replacePatterns !== [] && $strategy !== FortrabbitDeployStrategy::Merge) {
            throw ConfigException::invalidValue(
                self::KEY.'.replace_patterns',
                implode(', ', $replacePatterns),
                'no patterns unless '.self::KEY.'.strategy is '.FortrabbitDeployStrategy::Merge->value,
            );
        }

        return new self(
            cachedDirectories: self::stringListFrom($config, self::KEY.'.cached_directories', ['vendor', 'node_modules', '.pnpm-store']),
            strategy: $strategy,
            excludePatterns: self::stringListFrom($config, self::KEY.'.exclude_patterns', ['/storage', '/node_modules']),
            replacePatterns: $replacePatterns,
            gitSourceDirectory: self::optionalStringFrom($config, self::KEY.'.git_source_directory'),
        );
    }
}
