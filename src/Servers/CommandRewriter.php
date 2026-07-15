<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Process\ShellCommand;

/**
 * Applies a server's rewrite rules to a command, e.g. under Sail
 * `php artisan queue:work` becomes `./vendor/bin/sail artisan queue:work`.
 */
final class CommandRewriter
{
    public function rewrite(ShellCommand $command, ?CommandRewrites $rules): ShellCommand
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
