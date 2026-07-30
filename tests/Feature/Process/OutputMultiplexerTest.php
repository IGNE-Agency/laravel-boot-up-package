<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\CombinedService;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Process\OutputMultiplexer;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Serve\CombinedRunPlan;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->dir = sys_get_temp_dir().'/boot-up-multiplexer-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    $this->ledger = new ProcessLedger($this->dir.'/processes.json');
    $this->written = [];
    $this->sink = function (string $text): void {
        $this->written[] = $text;
    };
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Build AFTER Process::fake() so the multiplexer receives the fake factory.
 * A zero poll interval keeps the loop fast under test.
 */
function multiplexer(ProcessLedger $ledger, Closure $sink): OutputMultiplexer
{
    return new OutputMultiplexer(app(Factory::class), $ledger, pollIntervalMicroseconds: 0, write: $sink);
}

function combinedPlan(CombinedService ...$services): CombinedRunPlan
{
    $plan = new CombinedRunPlan;

    foreach ($services as $service) {
        $plan->add($service);
    }

    return $plan;
}

test('interleaves prefixed lines from concurrent workers until they exit', function (): void {
    Process::fake([
        '*queue:work*' => Process::describe()->output(['queue line 1', 'queue line 2'])->runsFor(iterations: 2),
        '*run*dev*' => Process::describe()->output(['vite ready'])->runsFor(iterations: 1),
    ]);

    multiplexer($this->ledger, $this->sink)->stream(combinedPlan(
        CombinedService::process('queue-worker', 'queue', CommandLine::make('php artisan queue:work')->withTimeout(null)),
        CombinedService::process('assets-watch', 'vite', CommandLine::make(['bun', 'run', 'dev'])->withTimeout(null)),
    ));

    $output = implode('', $this->written);

    expect($output)->toContain('[queue]')
        ->and($output)->toContain('[vite] ')
        ->and($output)->toContain('queue line 1')
        ->and($output)->toContain('queue line 2')
        ->and($output)->toContain('vite ready');
});

test('records a live combined child in the ledger with the combined mode', function (): void {
    Process::fake([
        '*queue:work*' => Process::describe()->id(4242)->output(['working', 'still working'])->runsFor(iterations: 10),
    ]);

    $instance = null;
    $sink = function (string $text) use (&$instance): void {
        $instance->stop();
    };

    $instance = multiplexer($this->ledger, $sink);

    $instance->stream(combinedPlan(
        CombinedService::process('queue-worker', 'queue', CommandLine::make('php artisan queue:work')->withTimeout(null)),
    ));

    $record = $this->ledger->withLabel('queue-worker')->first();

    expect($record)->not->toBeNull()
        ->and($record->pid)->toBe(4242)
        ->and($record->mode)->toBe(RunMode::Combined)
        ->and($record->outputLocation())->toBe('output streams in the app:serve terminal');
});

test('forgets the ledger record once the child exits', function (): void {
    Process::fake([
        '*queue:work*' => Process::describe()->id(4242)->output(['working'])->runsFor(iterations: 1),
    ]);

    multiplexer($this->ledger, $this->sink)->stream(combinedPlan(
        CombinedService::process('queue-worker', 'queue', CommandLine::make('php artisan queue:work')->withTimeout(null)),
    ));

    expect($this->ledger->isEmpty())->toBeTrue();
});

test('a worker crashing mid-stream warns and leaves the others running', function (): void {
    Process::fake([
        '*horizon*' => Process::describe()->output(['boom'])->exitCode(1)->runsFor(iterations: 1),
        '*queue:work*' => Process::describe()->output(['one', 'two', 'three'])->runsFor(iterations: 3),
    ]);

    multiplexer($this->ledger, $this->sink)->stream(combinedPlan(
        CombinedService::process('horizon', 'horizon', CommandLine::make('php artisan horizon')->withTimeout(null)),
        CombinedService::process('queue-worker', 'queue', CommandLine::make('php artisan queue:work')->withTimeout(null)),
    ));

    Prompt::assertStrippedOutputContains('[horizon] exited with code 1 — remaining services keep running.');

    expect(implode('', $this->written))->toContain('three');
});

test('tails a log file into the stream as its own prefixed source', function (): void {
    Process::fake([
        '*queue:work*' => Process::describe()->output(['working'])->runsFor(iterations: 2),
    ]);

    $logFile = $this->dir.'/artisan-serve.log';
    file_put_contents($logFile, "old line before streaming\n");

    $appended = false;
    $sink = function (string $text) use (&$appended, $logFile): void {
        $this->written[] = $text;

        if (! $appended) {
            $appended = true;
            file_put_contents($logFile, "Server running on [http://127.0.0.1:8000].\n", FILE_APPEND);
        }
    };

    multiplexer($this->ledger, $sink)->stream(combinedPlan(
        CombinedService::tail('artisan-serve', 'server', $logFile),
        CombinedService::process('queue-worker', 'queue', CommandLine::make('php artisan queue:work')->withTimeout(null)),
    ));

    $output = implode('', $this->written);

    // Only content appended after streaming began is emitted, as [server].
    expect($output)->toContain('[server]')
        ->and($output)->toContain('Server running on [http://127.0.0.1:8000].')
        ->and($output)->not->toContain('old line before streaming');
});

test('a tail alone never holds the stream open', function (): void {
    Process::fake();
    $logFile = $this->dir.'/artisan-serve.log';
    file_put_contents($logFile, "line\n");

    multiplexer($this->ledger, $this->sink)->stream(combinedPlan(
        CombinedService::tail('artisan-serve', 'server', $logFile),
    ));

    expect($this->written)->toBe([]);
});

test('stop() ends the loop while children are still running', function (): void {
    Process::fake([
        '*queue:work*' => Process::describe()->output(array_fill(0, 50, 'line'))->runsFor(iterations: 50),
    ]);

    $instance = null;
    $sink = function (string $text) use (&$instance): void {
        $this->written[] = $text;
        $instance->stop();
    };

    $instance = multiplexer($this->ledger, $sink);

    $instance->stream(combinedPlan(
        CombinedService::process('queue-worker', 'queue', CommandLine::make('php artisan queue:work')->withTimeout(null)),
    ));

    // The loop stopped after the first emitted line instead of draining all 50.
    expect(count($this->written))->toBeLessThan(10)
        ->and($this->ledger->isEmpty())->toBeFalse();
});

test('partial lines are buffered until their newline arrives and flushed at the end', function (): void {
    Process::fake([
        '*queue:work*' => Process::describe()->output(['first half — second half'])->runsFor(iterations: 1),
    ]);

    multiplexer($this->ledger, $this->sink)->stream(combinedPlan(
        CombinedService::process('queue-worker', 'queue', CommandLine::make('php artisan queue:work')->withTimeout(null)),
    ));

    $lines = array_values(array_filter($this->written, fn (string $line): bool => str_contains($line, 'half')));

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('first half — second half');
});
