<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\Lines;
use Igne\LaravelBootUp\Pipelines\FailureMessage;

test('lays out a headline, paragraphs and commands as indented echo lines', function (): void {
    $script = Lines::make();

    FailureMessage::make()
        ->headline('Commit aborted: Pint is not installed.')
        ->paragraph('Install the dependencies:', 'then commit again.')
        ->commands('composer install')
        ->appendTo($script);

    expect($script->toArray())->toBe([
        'echo',
        'echo "==> Commit aborted: Pint is not installed."',
        'echo',
        'echo "    Install the dependencies:"',
        'echo "    then commit again."',
        'echo',
        'echo "      composer install"',
    ]);
});

test('a conditional part renders as an if block around its own lines', function (): void {
    $script = Lines::make();

    FailureMessage::make()
        ->paragraph('Always.')
        ->when('[ -x vendor/bin/sail ]', fn (FailureMessage $message) => $message
            ->paragraph('Only with Sail.')
            ->blankLine()
            ->shell('print_files'))
        ->appendTo($script);

    expect($script->toArray())->toBe([
        'echo',
        'echo "    Always."',
        'if [ -x vendor/bin/sail ]; then',
        '  echo',
        '  echo "    Only with Sail."',
        '  echo',
        '  print_files',
        'fi',
    ]);
});

test('appendToStderr wraps the message in a group redirected to stderr', function (): void {
    $script = Lines::make()->line('if failed; then');

    $script->indent(2, fn (Lines $script) => FailureMessage::make()->headline('Failed.')->appendToStderr($script));

    expect($script->render())->toBe("if failed; then\n  {\n    echo\n    echo \"==> Failed.\"\n  } >&2\n");
});
