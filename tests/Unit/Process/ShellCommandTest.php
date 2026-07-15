<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Process\ShellCommand;

test('a string command is tokenized on whitespace', function (): void {
    expect(ShellCommand::make('php  artisan   serve')->tokens)->toBe(['php', 'artisan', 'serve']);
});

test('array elements are kept verbatim so arguments may contain spaces', function (): void {
    expect(ShellCommand::make(['php', '-r', 'echo PHP_VERSION;'])->tokens)->toBe(['php', '-r', 'echo PHP_VERSION;']);
});

test('withOptions renders flags, values and drops false or null entries', function (): void {
    $command = ShellCommand::make('php artisan queue:work')->withOptions([
        '--tries' => 3,
        '--force' => true,
        '--quiet' => false,
        '--memory' => null,
        '--daemon',
    ]);

    expect($command->tokens)->toBe(['php', 'artisan', 'queue:work', '--tries=3', '--force', '--daemon']);
});

test('withers return new instances and never mutate', function (): void {
    $original = ShellCommand::make('ls');
    $modified = $original->inDirectory('/tmp')->withEnv(['FOO' => 'bar'])->withTimeout(null);

    expect($original->cwd)->toBeNull()
        ->and($original->timeout)->toBe(300)
        ->and($modified->cwd)->toBe('/tmp')
        ->and($modified->env)->toBe(['FOO' => 'bar'])
        ->and($modified->timeout)->toBeNull()
        ->and($modified->tokens)->toBe(['ls']);
});

test('toString escapes tokens containing shell specials', function (): void {
    $command = ShellCommand::make(['echo', 'hello world', 'plain-token.txt']);

    expect($command->toString())->toBe("echo 'hello world' plain-token.txt");
});
