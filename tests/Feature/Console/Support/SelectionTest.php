<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Console\Support\Selection;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

test('a supplied argument is lowercased and returned without prompting', function (): void {
    Prompt::fake();

    $choice = (new Selection)->resolve('GitHub', ['github' => 'GitHub Actions'], 'Which provider?');

    expect($choice)->toBe('github');
    expect(Prompt::strippedContent())->toBe('');
});

test('an empty argument falls back to the interactive select', function (): void {
    Prompt::fake([Key::DOWN, Key::ENTER]);

    $choice = (new Selection)->resolve(null, ['github' => 'GitHub Actions', 'bitbucket' => 'Bitbucket'], 'Which provider?');

    expect($choice)->toBe('bitbucket');
    Prompt::assertStrippedOutputContains('Which provider?');
});

test('the prompt honors the provided default', function (): void {
    Prompt::fake([Key::ENTER]);

    $choice = (new Selection)->resolve('', ['a' => 'Alpha', 'b' => 'Beta'], 'Pick one', 'b');

    expect($choice)->toBe('b');
});
