<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\Steps\AnnounceApplication;
use Igne\LaravelBootUp\Boot\StepSequence;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Deploy\Steps\InstallComposerDependencies;
use Igne\LaravelBootUp\Deploy\Steps\RunDeployTasks;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Environment\Steps\EnsureEnvFile;
use Igne\LaravelBootUp\Servers\Steps\StartServer;

/**
 * The published default, read from the config file so this test cannot drift
 * from the pipeline the package actually ships.
 *
 * @return list<string>
 */
function defaultBootSteps(): array
{
    return (require dirname(__DIR__, 3).'/config/boot-up.php')['dev']['steps'];
}

test('assigns every default step to its stage, in order', function (): void {
    $plan = StepSequence::for(defaultBootSteps(), new BootOptions);

    $stages = array_map(fn ($step) => $step->stage, $plan->steps);

    expect($plan->count())->toBe(17)
        ->and($stages)->toBe([
            BootStage::Prepare, BootStage::Prepare, BootStage::Prepare,
            BootStage::Tools,
            BootStage::Server, BootStage::Install, BootStage::Install,
            BootStage::Database, BootStage::Database, BootStage::Database,
            BootStage::Database, BootStage::Database, BootStage::Database,
            BootStage::Cache, BootStage::Finalize,
            BootStage::Assets, BootStage::Announce,
        ]);
});

test('the default pipeline summarizes into eleven readable lines', function (): void {
    $plan = StepSequence::for(defaultBootSteps(), new BootOptions, 'Herd', ['server', 'queue', 'vite']);

    expect($plan->summary())->toBe([
        'Prepare the project (.env file, local environment, application key)',
        'Check required tools are installed',
        'Start the Herd development server',
        'Install Composer and frontend dependencies',
        'Prepare the database and verify the connection',
        "Run the project's configured commands",
        'Run pending migrations',
        'Cache the framework files',
        'Finalize the application',
        'Build frontend assets',
        'Announce the application URL',
        'Run the dev processes in this terminal: server, queue, vite',
    ]);
});

test('a variant entry parses its class and parameters', function (): void {
    $plan = StepSequence::for([RunDeployTasks::class.':before'], new BootOptions);

    expect($plan->steps[0]->class)->toBe(RunDeployTasks::class)
        ->and($plan->steps[0]->parameters)->toBe(['before'])
        ->and($plan->steps[0]->label)->toBe('Running project commands (before migrations)');
});

test('unknown classes inherit the preceding stage; leading unknowns are custom', function (): void {
    $plan = StepSequence::for([
        stdClass::class,
        StartServer::class,
        stdClass::class,
        AnnounceApplication::class,
    ], new BootOptions);

    expect(array_map(fn ($step) => $step->stage, $plan->steps))
        ->toBe([BootStage::Custom, BootStage::Server, BootStage::Server, BootStage::Announce])
        ->and($plan->steps[0]->label)->toBe('Std Class');
});

test('a reordered list may repeat stages', function (): void {
    $plan = StepSequence::for([
        StartServer::class,
        EnsureEnvFile::class,
        InstallComposerDependencies::class,
    ], new BootOptions);

    expect(array_map(fn ($step) => $step->stage, $plan->steps))
        ->toBe([BootStage::Server, BootStage::Prepare, BootStage::Install]);
});

test('--no-migrate hides the migrations line, even combined with --fresh', function (): void {
    $plan = StepSequence::for(defaultBootSteps(), new BootOptions(migrate: false, fresh: true));

    expect($plan->summary())->not->toContain('Run pending migrations')
        ->and(implode("\n", $plan->summary()))->not->toContain('migration');
});

test('--fresh and --seed adjust the migrations wording', function (): void {
    $fresh = StepSequence::for(defaultBootSteps(), new BootOptions(fresh: true));
    $seed = StepSequence::for(defaultBootSteps(), new BootOptions(seed: true));

    expect($fresh->summary())->toContain('Drop all tables and re-run every migration (asks first)')
        ->and($seed->summary())->toContain('Run pending migrations and seed the database');
});

test('--update switches the dependencies wording', function (): void {
    $plan = StepSequence::for(defaultBootSteps(), new BootOptions(update: true));

    expect($plan->summary())->toContain('Update Composer and frontend dependencies');
});

test('--without-assets drops the frontend fragments and the assets line', function (): void {
    $plan = StepSequence::for(defaultBootSteps(), new BootOptions(withAssets: false));

    expect($plan->summary())->toContain('Install Composer dependencies')
        ->and($plan->summary())->not->toContain('Build or watch frontend assets');
});

test('without a server label the server line stays generic', function (): void {
    $plan = StepSequence::for([StartServer::class], new BootOptions);

    expect($plan->summary())->toBe(['Start the development server']);
});

test('the dev processes are named on their own summary line', function (): void {
    $plan = StepSequence::for(defaultBootSteps(), new BootOptions, null, ['server', 'queue', 'stripe']);

    expect($plan->summary())->toContain('Run the dev processes in this terminal: server, queue, stripe');
});

test('a detached run says where the dev processes go instead', function (): void {
    $plan = StepSequence::for(defaultBootSteps(), new BootOptions(follow: false), null, ['queue', 'vite']);

    expect($plan->summary())->toContain('Run the dev processes in the background: queue, vite');
});

test('a boot with no dev processes carries no line for them', function (): void {
    $plan = StepSequence::for(defaultBootSteps(), new BootOptions);

    expect($plan->summary())->not->toContain('Run the dev processes in this terminal: ');
});

test('a step names itself through its Label attribute', function (): void {
    $plan = StepSequence::for([Igne\LaravelBootUp\Tests\Unit\Boot\Fixtures\LabelledStep::class], new BootOptions);

    expect($plan->steps[0]->label)->toBe('Seeding the search index');
});

test('a step can work its label out from the options and its own arguments', function (): void {
    $step = Igne\LaravelBootUp\Tests\Unit\Boot\Fixtures\OptionAwareStep::class;

    $plain = StepSequence::for([$step.':the index'], new BootOptions);
    $fresh = StepSequence::for([$step.':the index'], new BootOptions(fresh: true));

    expect($plain->steps[0]->label)->toBe('Refreshing the index')
        ->and($fresh->steps[0]->label)->toBe('Rebuilding the index');
});

test('the shipped steps carry their own labels', function (): void {
    $plan = StepSequence::for(defaultBootSteps(), new BootOptions(update: true, fresh: true));

    $labels = array_map(fn ($step) => $step->label, $plan->steps);

    expect($labels)->toContain('Checking the .env file')
        ->toContain('Updating Composer dependencies')
        ->toContain('Rebuilding the database from scratch')
        ->toContain('Running project commands (before migrations)')
        ->toContain('Running project commands (after migrations)');
});

test('no shipped step falls back to being named after its class', function (): void {
    $config = require dirname(__DIR__, 3).'/config/boot-up.php';
    $steps = [...$config['dev']['steps'], ...$config['deploy']['steps']];

    $plan = StepSequence::for($steps, new BootOptions);

    foreach ($plan->steps as $step) {
        $fallback = Illuminate\Support\Str::headline(class_basename($step->class));

        expect($step->label)->not->toBe($fallback);
    }
});
