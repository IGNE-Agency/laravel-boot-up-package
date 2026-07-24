<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Contracts\TerminalLauncher;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Exceptions\ProcessException;
use Igne\LaravelBootUp\Services\Poller;
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
 *  - startInTerminal(): new terminal window, real PID captured via pid file,
 *                       degrading to a background start() rather than aborting
 *                       when the window cannot be opened or does not report a PID
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
        private readonly int $terminalPidTimeout = 20,
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
     * real PID.
     *
     * A slow terminal launch never aborts the boot. Degrades, in order:
     *  - no terminal emulator available -> start() (background);
     *  - the window cannot be opened      -> start() (background);
     *  - the PID is not reported in time  -> recover it from the process table,
     *    and only if that also fails, close the window and start() (background).
     * The pid-file poll waits up to `terminalPidTimeout` seconds, because a new
     * window's shell may source a heavy startup profile before the shim runs.
     */
    public function startInTerminal(CommandLine $command, string $label): ProcessRecord
    {
        if (! $this->terminal->available()) {
            return $this->start($command, $label);
        }

        $suffix = bin2hex(random_bytes(4));
        $pidFile = "{$this->runtimeDirectory}/pids/{$label}-{$suffix}.pid";
        $this->ensureDirectory(\dirname($pidFile));

        $shim = sprintf(
            'echo $$ > %s; exec %s',
            escapeshellarg($pidFile),
            $command->toString(),
        );

        try {
            $window = $this->terminal->open($shim, $command->cwd ?? getcwd() ?: null);
        } catch (\Throwable $exception) {
            @unlink($pidFile);

            return $this->fallBackToBackground(
                $command,
                $label,
                "Could not open a terminal window for [{$label}] ({$exception->getMessage()})",
            );
        }

        $pid = $this->pidFromFile($pidFile);

        if ($pid > 0) {
            return $this->remember($pid, $label, $command, $window);
        }

        return $this->trackWindowWithoutPidFile($command, $label, $window);
    }

    /**
     * Wait for the shim to write the real PID, then remove the file. Zero
     * when nothing usable appeared within `terminalPidTimeout` seconds —
     * the poll is generous because a new window's shell may source a heavy
     * startup profile before the shim runs.
     */
    private function pidFromFile(string $pidFile): int
    {
        $captured = $this->poller->until(
            fn (): bool => is_file($pidFile) && trim((string) file_get_contents($pidFile)) !== '',
            timeoutSeconds: $this->terminalPidTimeout,
            intervalMs: 100,
        );

        $pid = $captured ? (int) trim((string) file_get_contents($pidFile)) : 0;
        @unlink($pidFile);

        return $pid;
    }

    /**
     * The window is open and the process may well be running; it just did
     * not write its PID in time (usually a slow shell startup). Recover the
     * real PID from the process table so the process stays tracked; only
     * when that also fails, close the window and start in the background so
     * the boot is never left with an untracked window and never aborts.
     */
    private function trackWindowWithoutPidFile(CommandLine $command, string $label, ?string $window): ProcessRecord
    {
        $recovered = $this->recoverPid($command);

        if ($recovered !== null) {
            terminal()->warning("The terminal window for [{$label}] was slow to report its PID — recovered it from the process table.");

            return $this->remember($recovered, $label, $command, $window);
        }

        $this->terminal->close($window);

        return $this->fallBackToBackground(
            $command,
            $label,
            "Could not capture a PID for [{$label}] from its terminal window",
            " (Tip: set its run_in option to 'background' to skip terminal windows.)",
        );
    }

    private function fallBackToBackground(CommandLine $command, string $label, string $reason, string $tip = ''): ProcessRecord
    {
        terminal()->warning("{$reason} — starting it in the background instead.{$tip}");

        return $this->start($command, $label);
    }

    public function isCommandAvailable(string $binary): bool
    {
        return $this->runSilently(CommandLine::make(['sh', '-c', 'command -v '.escapeshellarg($binary)]))
            ->successful();
    }

    /**
     * Best-effort PID recovery for a process just launched in a terminal window
     * whose pid file was not written in time. Matches the command against the
     * process table, newest match first (`pgrep -fn`), so the process we just
     * started wins over any older look-alike. Null when nothing matches — e.g.
     * the window's shell has not exec'd the command yet, or it never started.
     */
    private function recoverPid(CommandLine $command): ?int
    {
        $signature = implode(' ', $command->tokens);

        if ($signature === '') {
            return null;
        }

        $result = $this->processes->command(['pgrep', '-fn', $signature])->run();

        if (! $result->successful()) {
            return null;
        }

        $pid = (int) trim($result->output());

        return $pid > 0 ? $pid : null;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function pending(CommandLine $command, array $tokens): PendingProcess
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

    private function remember(int $pid, string $label, CommandLine $command, ?string $window = null): ProcessRecord
    {
        $record = new ProcessRecord(
            pid: $pid,
            label: $label,
            command: $command->toString(),
            startedAt: date(DATE_ATOM),
            window: $window,
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
