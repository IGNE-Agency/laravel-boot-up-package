<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\ShellCommand;
use Igne\LaravelBootUp\Servers\CommandRewriter;

function sailRewrites(): CommandRewrites
{
    return new CommandRewrites(
        replaces: ['php artisan' => 'artisan'],
        prefixes: ['php', 'composer', 'yarn', 'npm', 'bun', 'artisan', 'node'],
        prefix: './vendor/bin/sail',
    );
}

function herdRewrites(): CommandRewrites
{
    return new CommandRewrites(
        prefixes: ['php', 'composer', 'tinker'],
        prefix: 'herd',
    );
}

test('sail rewrites php artisan commands to sail artisan', function (): void {
    $rewritten = new CommandRewriter()->rewrite(ShellCommand::make('php artisan queue:work database'), sailRewrites());

    expect($rewritten->tokens)->toBe(['./vendor/bin/sail', 'artisan', 'queue:work', 'database']);
});

test('sail prefixes bare package manager commands', function (): void {
    $rewritten = new CommandRewriter()->rewrite(ShellCommand::make('bun install'), sailRewrites());

    expect($rewritten->tokens)->toBe(['./vendor/bin/sail', 'bun', 'install']);
});

test('herd prefixes php but leaves other binaries alone', function (): void {
    $rewriter = new CommandRewriter;

    expect($rewriter->rewrite(ShellCommand::make('php -v'), herdRewrites())->tokens)
        ->toBe(['herd', 'php', '-v'])
        ->and($rewriter->rewrite(ShellCommand::make('git status'), herdRewrites())->tokens)
        ->toBe(['git', 'status']);
});

test('null rules and empty rules are identity', function (): void {
    $command = ShellCommand::make('php artisan serve');
    $rewriter = new CommandRewriter;

    expect($rewriter->rewrite($command, null))->toBe($command)
        ->and($rewriter->rewrite($command, CommandRewrites::none()))->toBe($command);
});

test('only the leading binary is prefixed, never later tokens', function (): void {
    $rewritten = new CommandRewriter()->rewrite(ShellCommand::make('composer run php-check'), sailRewrites());

    expect($rewritten->tokens)->toBe(['./vendor/bin/sail', 'composer', 'run', 'php-check']);
});

test('cwd, env and timeout survive rewriting', function (): void {
    $command = ShellCommand::make('php artisan migrate')->inDirectory('/app')->withTimeout(null);
    $rewritten = new CommandRewriter()->rewrite($command, sailRewrites());

    expect($rewritten->cwd)->toBe('/app')->and($rewritten->timeout)->toBeNull();
});
