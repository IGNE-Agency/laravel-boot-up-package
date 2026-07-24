<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Database\Steps\EnsureDatabaseCredentials;
use Igne\LaravelBootUp\Database\Steps\EnsureDatabaseExists;
use Igne\LaravelBootUp\Database\Steps\RunPendingMigrations;
use Igne\LaravelBootUp\Database\Steps\VerifyDatabaseConnection;
use Igne\LaravelBootUp\Deploy\Steps\CacheFrameworkFiles;
use Igne\LaravelBootUp\Deploy\Steps\FinalizeApplication;
use Igne\LaravelBootUp\Deploy\Steps\InstallComposerDependencies;
use Igne\LaravelBootUp\Deploy\Steps\RunProjectCommands;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Environment\Steps\EnsureEnvFile;
use Igne\LaravelBootUp\Environment\Steps\EnsureLocalEnvironment;
use Igne\LaravelBootUp\Environment\Steps\GenerateAppKey;
use Igne\LaravelBootUp\Frontend\Steps\BuildOrWatchAssets;
use Igne\LaravelBootUp\Frontend\Steps\InstallFrontendDependencies;
use Igne\LaravelBootUp\Queue\Steps\StartQueueWorker;
use Igne\LaravelBootUp\Serve\Steps\AnnounceApplication;
use Igne\LaravelBootUp\Serve\StepSequence;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Igne\LaravelBootUp\Services\Steps\StartHorizon;
use Igne\LaravelBootUp\Services\Steps\StartReverb;
use Igne\LaravelBootUp\Services\Steps\StartScheduler;
use Igne\LaravelBootUp\Tools\Steps\EnsureToolsReady;

function defaultServeSteps(): array
{
    return [
        EnsureEnvFile::class,
        EnsureLocalEnvironment::class,
        GenerateAppKey::class,
        EnsureToolsReady::class,
        StartServer::class,
        InstallComposerDependencies::class,
        InstallFrontendDependencies::class,
        EnsureDatabaseCredentials::class,
        EnsureDatabaseExists::class,
        VerifyDatabaseConnection::class,
        RunProjectCommands::class.':before',
        RunPendingMigrations::class,
        RunProjectCommands::class.':after',
        CacheFrameworkFiles::class,
        FinalizeApplication::class,
        StartQueueWorker::class,
        StartHorizon::class,
        StartReverb::class,
        StartScheduler::class,
        BuildOrWatchAssets::class,
        AnnounceApplication::class,
    ];
}

test('assigns every default step to its stage, in order', function (): void {
    $plan = StepSequence::for(defaultServeSteps(), new ServeOptions);

    $stages = array_map(fn ($step) => $step->stage, $plan->steps);

    expect($plan->count())->toBe(21)
        ->and($stages)->toBe([
            ServeStage::Prepare, ServeStage::Prepare, ServeStage::Prepare,
            ServeStage::Tools,
            ServeStage::Server, ServeStage::Install, ServeStage::Install,
            ServeStage::Database, ServeStage::Database, ServeStage::Database,
            ServeStage::Database, ServeStage::Database, ServeStage::Database,
            ServeStage::Cache, ServeStage::Finalize,
            ServeStage::Services, ServeStage::Services, ServeStage::Services, ServeStage::Services,
            ServeStage::Assets, ServeStage::Announce,
        ]);
});

test('the default pipeline summarizes into twelve readable lines', function (): void {
    $plan = StepSequence::for(defaultServeSteps(), new ServeOptions, 'Herd');

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
        'Start long-running services when enabled: queue worker, Horizon, Reverb, scheduler',
        'Build or watch frontend assets',
        'Announce the application URL',
    ]);
});

test('a variant entry parses its class and parameters', function (): void {
    $plan = StepSequence::for([RunProjectCommands::class.':before'], new ServeOptions);

    expect($plan->steps[0]->class)->toBe(RunProjectCommands::class)
        ->and($plan->steps[0]->parameters)->toBe(['before'])
        ->and($plan->steps[0]->label)->toBe('Running project commands (before migrations)');
});

test('unknown classes inherit the preceding stage; leading unknowns are custom', function (): void {
    $plan = StepSequence::for([
        stdClass::class,
        StartServer::class,
        stdClass::class,
        AnnounceApplication::class,
    ], new ServeOptions);

    expect(array_map(fn ($step) => $step->stage, $plan->steps))
        ->toBe([ServeStage::Custom, ServeStage::Server, ServeStage::Server, ServeStage::Announce])
        ->and($plan->steps[0]->label)->toBe('Std Class');
});

test('a reordered list may repeat stages', function (): void {
    $plan = StepSequence::for([
        StartServer::class,
        EnsureEnvFile::class,
        InstallComposerDependencies::class,
    ], new ServeOptions);

    expect(array_map(fn ($step) => $step->stage, $plan->steps))
        ->toBe([ServeStage::Server, ServeStage::Prepare, ServeStage::Install]);
});

test('--no-migrate hides the migrations line, even combined with --fresh', function (): void {
    $plan = StepSequence::for(defaultServeSteps(), new ServeOptions(migrate: false, fresh: true));

    expect($plan->summary())->not->toContain('Run pending migrations')
        ->and(implode("\n", $plan->summary()))->not->toContain('migration');
});

test('--fresh and --seed adjust the migrations wording', function (): void {
    $fresh = StepSequence::for(defaultServeSteps(), new ServeOptions(fresh: true));
    $seed = StepSequence::for(defaultServeSteps(), new ServeOptions(seed: true));

    expect($fresh->summary())->toContain('Drop all tables and re-run every migration (asks first)')
        ->and($seed->summary())->toContain('Run pending migrations and seed the database');
});

test('--update switches the dependencies wording', function (): void {
    $plan = StepSequence::for(defaultServeSteps(), new ServeOptions(update: true));

    expect($plan->summary())->toContain('Update Composer and frontend dependencies');
});

test('--without-assets drops the frontend fragments and the assets line', function (): void {
    $plan = StepSequence::for(defaultServeSteps(), new ServeOptions(withAssets: false));

    expect($plan->summary())->toContain('Install Composer dependencies')
        ->and($plan->summary())->not->toContain('Build or watch frontend assets');
});

test('--without-queue drops the queue worker from the services line', function (): void {
    $plan = StepSequence::for(defaultServeSteps(), new ServeOptions(withQueue: false));

    expect($plan->summary())->toContain('Start long-running services when enabled: Horizon, Reverb, scheduler');
});

test('without a server label the server line stays generic', function (): void {
    $plan = StepSequence::for([StartServer::class], new ServeOptions);

    expect($plan->summary())->toBe(['Start the development server']);
});
