<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Queue\Steps\QueueWorker;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

/**
 * Call AFTER Process::fake() so the runner and reaper receive the faked factory.
 */
function bindQueueServices(string $dir, ?QueueConfig $config = null): ProcessLedger
{
    $ledger = new ProcessLedger($dir.'/processes.json');

    app()->instance(QueueConfig::class, $config ?? new QueueConfig);
    app()->instance(EnvFile::class, new EnvFile($dir.'/.env', $dir.'/.env.example'));
    app()->instance(ProcessLedger::class, $ledger);
    app()->instance(ProcessReaper::class, new ProcessReaper(app(Factory::class), $ledger, new Poller, new NullTerminalLauncher));
    app()->instance(ProcessRunner::class, new ProcessRunner(
        processes: app(Factory::class),
        ledger: $ledger,
        terminal: new NullTerminalLauncher,
        poller: new Poller,
        logDirectory: $dir.'/logs',
        runtimeDirectory: $dir.'/runtime',
    ));

    return $ledger;
}

function queueSailServer(): Server
{
    return new class implements RewritesCommands, Server
    {
        public function key(): string
        {
            return 'sail';
        }

        public function label(): string
        {
            return 'Laravel Sail';
        }

        public function commandRewrites(): CommandRewrites
        {
            return new CommandRewrites(
                replaces: ['php artisan' => 'artisan'],
                prefixes: ['php', 'composer', 'yarn', 'npm', 'bun', 'artisan', 'node'],
                prefix: './vendor/bin/sail',
            );
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function start(ServeContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-queue-worker-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    Prompt::fake();
});

afterEach(function (): void {
    if (is_dir($this->dir)) {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
});

test('skips with a note when --without-queue was passed', function (): void {
    Process::fake();
    bindQueueServices($this->dir);

    $context = new ServeContext(new ServeOptions(withQueue: false));

    $result = app(QueueWorker::class)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('--without-queue');
});

test('skips with a note when the queue is disabled in configuration', function (): void {
    Process::fake();
    bindQueueServices($this->dir, new QueueConfig(enabled: false));

    app(QueueWorker::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('disabled in configuration');
});

test('skips with a note when horizon is installed and enabled', function (): void {
    Process::fake();
    bindQueueServices($this->dir);
    file_put_contents($this->dir.'/composer.json', (string) json_encode(['require' => ['laravel/horizon' => '^6.0']]));
    app()->instance(Igne\LaravelBootUp\Pipelines\ComposerJson::class, new Igne\LaravelBootUp\Pipelines\ComposerJson($this->dir.'/composer.json'));

    app(QueueWorker::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('laravel/horizon manages the queue');
});

test('skips with a note when the .env queue connection is sync', function (): void {
    Process::fake();
    bindQueueServices($this->dir);
    file_put_contents($this->dir.'/.env', "QUEUE_CONNECTION=sync\n");
    config()->set('queue.default', 'database');

    app(QueueWorker::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('no worker needed');
});

test('falls back to config(queue.default) when .env has no connection', function (): void {
    Process::fake();
    bindQueueServices($this->dir);
    config()->set('queue.default', 'sync');

    app(QueueWorker::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('no worker needed');
});

test('spawns a tracked queue worker with the connection and configured flags', function (): void {
    Process::fake(['*' => Process::result(output: "4242\n")]);
    $ledger = bindQueueServices($this->dir, new QueueConfig(runIn: RunMode::Background, flags: ['--tries' => 3]));
    file_put_contents($this->dir.'/.env', "QUEUE_CONNECTION=database\n");

    app(QueueWorker::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertRan(function ($process): bool {
        $command = implode(' ', $process->command);

        return str_contains($command, 'nohup php artisan queue:work database --tries=3')
            && str_contains($command, 'queue-worker.log')
            && $process->timeout === null;
    });

    $records = $ledger->withLabel('queue-worker');

    expect($records)->toHaveCount(1)
        ->and($records->first()->pid)->toBe(4242);
    Prompt::assertStrippedOutputContains('queue-worker.log');
});

test('the worker command is rewritten for the active server', function (): void {
    Process::fake(['*' => Process::result(output: "4242\n")]);
    bindQueueServices($this->dir, new QueueConfig(runIn: RunMode::Background));
    file_put_contents($this->dir.'/.env', "QUEUE_CONNECTION=database\n");

    $context = new ServeContext(new ServeOptions, queueSailServer());

    app(QueueWorker::class)->handle($context, fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => str_contains(
        implode(' ', $process->command),
        'nohup ./vendor/bin/sail artisan queue:work database',
    ));
});

test('a live queue-worker record skips spawning a second worker', function (): void {
    Process::fake([
        "'kill'*" => Process::result(),
        "'ps'*" => Process::result(output: "php artisan queue:work database\n"),
        '*' => Process::result(output: "9999\n"),
    ]);
    $ledger = bindQueueServices($this->dir);
    file_put_contents($this->dir.'/.env', "QUEUE_CONNECTION=database\n");

    $ledger->record(new ProcessRecord(
        pid: 4242,
        label: 'queue-worker',
        command: 'php artisan queue:work database',
        startedAt: date(DATE_ATOM),
    ));

    app(QueueWorker::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', $process->command), 'nohup'));

    expect($ledger->withLabel('queue-worker'))->toHaveCount(1);
    Prompt::assertStrippedOutputContains('already running');
});

test('the .env connection beats a stale config(queue.default)', function (): void {
    Process::fake(['*' => Process::result(output: "4242\n")]);
    bindQueueServices($this->dir, new QueueConfig(runIn: RunMode::Background));
    file_put_contents($this->dir.'/.env', "QUEUE_CONNECTION=database\n");
    config()->set('queue.default', 'sync');

    app(QueueWorker::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => str_contains(
        implode(' ', $process->command),
        'nohup php artisan queue:work database',
    ));
});
