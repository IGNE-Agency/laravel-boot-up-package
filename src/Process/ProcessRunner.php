<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Exceptions\ProcessException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;

/**
 * The only place in the package that executes OS commands.
 *
 * Modes:
 *  - run():             synchronous, output streamed live, throws on failure
 *  - runSilently():     synchronous, output captured, never throws on failure
 *  - start():           detached background process, PID persisted to the ledger
 */
final class ProcessRunner
{
    public function __construct(
        private readonly Factory $processes,
        private readonly ProcessLedger $ledger,
        private readonly string $logDirectory,
    ) {}

    public function run(CommandLine $command): ProcessResult
    {
        // Stream sub-process output with the active progress bar out of the
        // way: it is erased before the output and redrawn once afterward, so
        // the live stream never corrupts the bar's frame diffing.
        return terminal()->suspend(fn (): ProcessResult => $this->pending($command, $command->tokens)
            ->run(null, function (string $type, string $buffer): void {
                fwrite($type === 'err' ? STDERR : STDOUT, $buffer);
            })
            ->throw());
    }

    public function runSilently(CommandLine $command): ProcessResult
    {
        return $this->pending($command, $command->tokens)->run();
    }

    /**
     * Start a detached background process that survives this PHP process.
     * Output is appended to storage/logs/boot-up/{label}.log.
     */
    public function start(CommandLine $command, string $label): ProcessRecord
    {
        $logFile = $this->logFile($label);
        $this->ensureDirectory(\dirname($logFile));

        // nohup exec()s the command, so the echoed PID belongs to the real
        // process — and it survives this PHP process exiting after boot.
        $wrapper = sprintf(
            'nohup %s >> %s 2>&1 & echo $!',
            $command->toString(),
            escapeshellarg($logFile),
        );

        $result = $this->pending($command, ['sh', '-c', $wrapper])->run()->throw();

        $pid = (int) trim($result->output());

        if ($pid <= 0) {
            throw ProcessException::pidNotCaptured($label);
        }

        return $this->remember($pid, $label, $command);
    }

    /**
     * Where a background process with this label appends its output.
     */
    public function logFile(string $label): string
    {
        return "{$this->logDirectory}/{$label}.log";
    }

    public function isCommandAvailable(string $binary): bool
    {
        $quoted = escapeshellarg($binary);

        return $this->runSilently(CommandLine::make(['sh', '-c', "command -v {$quoted}"]))
            ->successful();
    }

    /**
     * @param  list<string>  $tokens
     */
    private function pending(CommandLine $command, array $tokens): PendingProcess
    {
        return PendingProcessBuilder::build($this->processes, $command, $tokens);
    }

    private function remember(int $pid, string $label, CommandLine $command): ProcessRecord
    {
        $record = new ProcessRecord(
            pid: $pid,
            label: $label,
            command: $command->toString(),
            startedAt: date(DATE_ATOM),
        );

        $this->ledger->record($record);

        return $record;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
