<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\CommandRewrites;

/**
 * Applies a server's rewrite rules to a command, e.g. under Sail
 * `php artisan queue:work` becomes `./vendor/bin/sail artisan queue:work`.
 */
final class CommandRewriter
{
    /**
     * Rewrite for whatever server this serve run booted; a null server
     * (app:deploy) or one without rewrites leaves the command untouched.
     */
    public function rewriteFor(BootContext $context, CommandLine $command): CommandLine
    {
        return $this->rewrite($command, $context->commandRewrites());
    }

    public function rewrite(CommandLine $command, ?CommandRewrites $rules): CommandLine
    {
        if ($rules === null || $command->tokens === []) {
            return $command;
        }

        $tokens = $this->applyReplaces($command->tokens, $rules->replaces);
        $tokens = $this->applyPrefix($tokens, $rules);

        return $tokens === $command->tokens ? $command : $command->withTokens($tokens);
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, string>  $replaces
     * @return list<string>
     */
    private function applyReplaces(array $tokens, array $replaces): array
    {
        foreach ($replaces as $search => $replacement) {
            $searchTokens = explode(' ', $search);

            if (\array_slice($tokens, 0, \count($searchTokens)) === $searchTokens) {
                return [...explode(' ', $replacement), ...\array_slice($tokens, \count($searchTokens))];
            }
        }

        return $tokens;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function applyPrefix(array $tokens, CommandRewrites $rules): array
    {
        if ($rules->prefix === null || ! \in_array($tokens[0] ?? null, $rules->prefixes, true)) {
            return $tokens;
        }

        return [...explode(' ', $rules->prefix), ...$tokens];
    }
}
