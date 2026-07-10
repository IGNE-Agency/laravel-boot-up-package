<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Process;

use Igne\LaravelBootstrap\Process\Terminal\TerminalLauncher;
use Igne\LaravelBootstrap\Support\Poller;
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
 *  - startInTerminal(): new terminal window, real PID captured via pid file
 */
final class ProcessRunner
{
    public function __construct(
        private readonly Factory $processes,
        private readonly ProcessLedger $ledger,
        private readonly TerminalLauncher $terminal,
        private readonly Poller $poller,
        private readonly string $logDirectory,
        private readonly string $runtimeDirectory,
    ) {}

    public function run(ShellCommand $command): ProcessResult
    {
        return $this->pending($command, $command->tokens)
            ->run(null, function (string $type, string $buffer): void {
                fwrite($type === 'err' ? STDERR : STDOUT, $buffer);
            })
            ->throw();
    }

    public function runSilently(ShellCommand $command): ProcessResult
    {
        return $this->pending($command, $command->tokens)->run();
    }

    /**
     * Start a detached background process that survives this PHP process.
     * Output is appended to storage/logs/bootstrap/{label}.log.
     */
    public function start(ShellCommand $command, string $label): ProcessRecord
    {
        $logFile = $this->logDirectory.'/'.$label.'.log';
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
     * Run the command in a new terminal window. The command is wrapped in a
     * pid-file shim (`echo $$ > pidfile; exec ...`) so the ledger records the
     * real PID. Falls back to start() when no terminal emulator is available.
     */
    public function startInTerminal(ShellCommand $command, string $label): ProcessRecord
    {
        if (! $this->terminal->available()) {
            return $this->start($command, $label);
        }

        $pidFile = $this->runtimeDirectory.'/pids/'.$label.'-'.bin2hex(random_bytes(4)).'.pid';
        $this->ensureDirectory(\dirname($pidFile));

        $shim = sprintf(
            'echo $$ > %s; exec %s',
            escapeshellarg($pidFile),
            $command->toString(),
        );

        $this->terminal->open($shim, $command->cwd ?? getcwd() ?: null);

        $captured = $this->poller->until(
            fn (): bool => is_file($pidFile) && trim((string) file_get_contents($pidFile)) !== '',
            timeoutSeconds: 5,
            intervalMs: 100,
        );

        if (! $captured) {
            throw ProcessException::terminalPidNotCaptured($label);
        }

        $pid = (int) trim((string) file_get_contents($pidFile));
        @unlink($pidFile);

        if ($pid <= 0) {
            throw ProcessException::terminalPidNotCaptured($label);
        }

        return $this->remember($pid, $label, $command);
    }

    public function isCommandAvailable(string $binary): bool
    {
        return $this->runSilently(ShellCommand::make(['sh', '-c', 'command -v '.escapeshellarg($binary)]))
            ->successful();
    }

    /**
     * @param  list<string>  $tokens
     */
    private function pending(ShellCommand $command, array $tokens): PendingProcess
    {
        $pending = $this->processes->command($tokens);

        if ($command->cwd !== null) {
            $pending = $pending->path($command->cwd);
        }

        if ($command->env !== []) {
            $pending = $pending->env($command->env);
        }

        return $command->timeout === null
            ? $pending->forever()
            : $pending->timeout($command->timeout);
    }

    private function remember(int $pid, string $label, ShellCommand $command): ProcessRecord
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
