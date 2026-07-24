<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Services\Terminal;
use Igne\LaravelBootUp\Services\TrackedProgress;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

test('success renders a green info line', function (): void {
    Prompt::fake();

    (new Terminal)->success('Database created.');

    Prompt::assertStrippedOutputContains('Database created.');
    expect(Prompt::content())->toContain("\e[32m");
});

test('info renders a plain line', function (): void {
    Prompt::fake();

    (new Terminal)->info('Installing dependencies...');

    Prompt::assertStrippedOutputContains('Installing dependencies...');
    expect(Prompt::content())->not->toContain("\e[32m");
});

test('note renders dimmed, per line for arrays', function (): void {
    Prompt::fake();

    (new Terminal)->note(['first hint', 'second hint']);

    Prompt::assertStrippedOutputContains('first hint');
    Prompt::assertStrippedOutputContains('second hint');
    expect(substr_count(Prompt::content(), "\e[2m"))->toBe(2);
});

test('warning, error, intro and outro delegate to prompts', function (): void {
    Prompt::fake();
    $terminal = new Terminal;

    $terminal->intro('Starting...');
    $terminal->warning('Careful.');
    $terminal->error('Broken.');
    $terminal->outro('Done.');

    Prompt::assertStrippedOutputContains('Starting...');
    Prompt::assertStrippedOutputContains('Careful.');
    Prompt::assertStrippedOutputContains('Broken.');
    Prompt::assertStrippedOutputContains('Done.');
});

test('heading renders the title', function (): void {
    Prompt::fake();

    (new Terminal)->heading('Next steps');

    Prompt::assertStrippedOutputContains('Next steps');
});

test('section renders title, description and indented lines in one block', function (): void {
    Prompt::fake();

    (new Terminal)->section('Prepare database', ['Create the database', 'Run migrations'], 'Credentials come from .env');

    Prompt::assertStrippedOutputContains('Prepare database');
    Prompt::assertStrippedOutputContains('Credentials come from .env');
    Prompt::assertStrippedOutputContains('  Create the database');
    Prompt::assertStrippedOutputContains('  Run migrations');
});

test('a section without body still renders its title as a divider', function (): void {
    Prompt::fake();

    (new Terminal)->section('Check required tools');

    Prompt::assertStrippedOutputContains('Check required tools');
});

test('list renders one bullet per item', function (): void {
    Prompt::fake();

    (new Terminal)->list(['Install dependencies', 'Run migrations']);

    Prompt::assertStrippedOutputContains('• Install dependencies');
    Prompt::assertStrippedOutputContains('• Run migrations');
});

test('summary renders title, bullets and footer', function (): void {
    Prompt::fake();

    (new Terminal)->summary('Dependencies ready', ['PHP 8.4.1', 'Node.js'], 'All required dependencies are installed.');

    Prompt::assertStrippedOutputContains('Dependencies ready');
    Prompt::assertStrippedOutputContains('• PHP 8.4.1');
    Prompt::assertStrippedOutputContains('• Node.js');
    Prompt::assertStrippedOutputContains('All required dependencies are installed.');
});

test('orderedList renders a numbered list under a title', function (): void {
    Prompt::fake();

    (new Terminal)->orderedList('Next steps', ['Commit the files', 'Enable pipelines', 'Add the secret']);

    Prompt::assertStrippedOutputContains('Next steps');
    Prompt::assertStrippedOutputContains('1. Commit the files');
    Prompt::assertStrippedOutputContains('2. Enable pipelines');
    Prompt::assertStrippedOutputContains('3. Add the secret');
});

test('suspend returns the callback result whether or not a bar is active', function (): void {
    Prompt::fake();
    $terminal = new Terminal;

    expect($terminal->suspend(fn (): int => 42))->toBe(42);

    $progress = $terminal->progress('Boot progress', 2);
    $progress->start();

    expect($terminal->suspend(fn (): string => 'ok'))->toBe('ok');
});

test('table renders headers and rows', function (): void {
    Prompt::fake();

    (new Terminal)->table(['Secret', 'Purpose'], [['DEPLOY_HOOK', 'deploys development']]);

    Prompt::assertStrippedOutputContains('Secret');
    Prompt::assertStrippedOutputContains('DEPLOY_HOOK');
});

test('blank writes an empty note line', function (): void {
    Prompt::fake();

    (new Terminal)->blank();

    expect(Prompt::strippedContent())->not->toBe('');
});

test('confirm returns the default on enter and false on decline', function (): void {
    Prompt::fake([Key::ENTER]);
    expect((new Terminal)->confirm('Continue?'))->toBeTrue();

    Prompt::fake(['n', Key::ENTER]);
    expect((new Terminal)->confirm('Continue?'))->toBeFalse();
});

test('select returns the chosen key', function (): void {
    Prompt::fake([Key::DOWN, Key::ENTER]);

    $choice = (new Terminal)->select('Pick one', ['a' => 'Alpha', 'b' => 'Beta']);

    expect($choice)->toBe('b');
});

test('text honors the default and returns typed input', function (): void {
    Prompt::fake([Key::ENTER]);
    expect((new Terminal)->text('Name?', default: 'fallback'))->toBe('fallback');

    Prompt::fake(['h', 'i', Key::ENTER]);
    expect((new Terminal)->text('Name?'))->toBe('hi');
});

test('password allows an empty value when not required', function (): void {
    Prompt::fake([Key::ENTER]);

    expect((new Terminal)->password('Password?', required: false))->toBe('');
});

test('progress registers a tracked bar that output suspends and redraws', function (): void {
    Prompt::fake();
    $terminal = new Terminal;

    $progress = $terminal->progress('Boot progress', 3, 'First step');
    $progress->start();

    $terminal->info('mid-run message');

    $content = Prompt::strippedContent();
    $afterMessage = substr($content, (int) strpos($content, 'mid-run message'));

    expect($progress)->toBeInstanceOf(TrackedProgress::class)
        ->and($afterMessage)->toContain('Boot progress');
});

test('finish detaches the bar so later output no longer suspends', function (): void {
    Prompt::fake();
    $terminal = new Terminal;

    $progress = $terminal->progress('Boot progress', 2);
    $progress->start();
    $progress->advance(2);
    $progress->finish();

    $terminal->info('after the bar');

    $content = Prompt::strippedContent();
    $afterMessage = substr($content, (int) strpos($content, 'after the bar'));

    expect($afterMessage)->not->toContain('Boot progress');
});

test('fail settles the bar in its error state and detaches it', function (): void {
    Prompt::fake();
    $terminal = new Terminal;

    $progress = $terminal->progress('Boot progress', 2);
    $progress->start();
    $progress->advance();
    $progress->fail();

    $terminal->error('something exploded');

    $content = Prompt::strippedContent();
    $afterError = substr($content, (int) strpos($content, 'something exploded'));

    expect($progress->isRendered())->toBeFalse()
        ->and($afterError)->not->toContain('Boot progress');
});
