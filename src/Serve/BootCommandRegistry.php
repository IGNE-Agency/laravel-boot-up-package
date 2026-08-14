<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Enums\BootProcessKind;
use Igne\LaravelBootUp\Enums\RegistrationSource;
use Igne\LaravelBootUp\Exceptions\BootCommandException;
use ReflectionClass;

/**
 * The BootCommands registration slate: providers add dev processes here
 * during boot, app:serve launches the survivors alongside the built-in
 * workers. Names are slots — a later registration replaces an earlier one
 * of the same name when its source allows it, and a name matching a
 * built-in worker's stream replaces that built-in.
 */
final class BootCommandRegistry
{
    /**
     * The stream names of the built-in workers a registration may replace.
     */
    public const array BUILT_IN_STREAMS = ['queue', 'horizon', 'reverb', 'scheduler', 'vite'];

    /**
     * The development server's stream name — never registrable.
     */
    public const string RESERVED_STREAM = 'server';

    /** @var array<string, PendingBootProcess> */
    private array $commands = [];

    /** @var list<string> */
    private array $only = [];

    /** @var list<string> */
    private array $except = [];

    public function __construct(
        private readonly bool $runningInConsole,
        private readonly string $vendorPath,
    ) {}

    /**
     * Register a shell command as a dev process. The name defaults to the
     * command's first token; $source is an explicit override for tests —
     * normally the call site's backtrace decides.
     */
    public function register(string $command, ?string $name = null, ?RegistrationSource $source = null): PendingBootProcess
    {
        return $this->add(BootProcessKind::Shell, $command, $name, $source);
    }

    /**
     * Register an artisan command, prefixed with "php artisan".
     */
    public function artisan(string $command, ?string $name = null, ?RegistrationSource $source = null): PendingBootProcess
    {
        return $this->add(BootProcessKind::Artisan, $command, $name, $source);
    }

    /**
     * Register a command run through the project's package manager, exactly
     * like DeployTask::packageManager(): packageManager('run dev') becomes
     * `bun run dev`. Names itself after the script when the command is a
     * `run <script>`.
     */
    public function packageManager(string $command, ?string $name = null, ?RegistrationSource $source = null): PendingBootProcess
    {
        return $this->add(BootProcessKind::PackageManager, $command, $name, $source);
    }

    /**
     * Register a package executable through the manager's exec runner:
     * packageManagerExec('vite') becomes `bunx vite` (npx / pnpm exec /
     * yarn exec for the others).
     */
    public function packageManagerExec(string $command, ?string $name = null, ?RegistrationSource $source = null): PendingBootProcess
    {
        return $this->add(BootProcessKind::PackageManagerExec, $command, $name, $source);
    }

    /**
     * Limit app:serve's workers — built-in and registered — to these
     * stream names. Merged across calls.
     */
    public function only(string ...$streamNames): void
    {
        if ($this->runningInConsole) {
            $this->only = array_values(array_unique([...$this->only, ...$streamNames]));
        }
    }

    /**
     * Exclude these stream names from app:serve's workers — built-in and
     * registered. Merged across calls.
     */
    public function except(string ...$streamNames): void
    {
        if ($this->runningInConsole) {
            $this->except = array_values(array_unique([...$this->except, ...$streamNames]));
        }
    }

    /**
     * Whether a worker with this stream name may run under the only/except
     * filters.
     */
    public function allows(string $streamName): bool
    {
        return ($this->only === [] || \in_array($streamName, $this->only, true))
            && ! \in_array($streamName, $this->except, true);
    }

    /**
     * Whether a launchable registration claims this stream name — the
     * built-in worker behind it stands down.
     */
    public function replaces(string $streamName): bool
    {
        return isset($this->commands[$streamName]) && $this->allows($streamName);
    }

    /**
     * The registrations app:serve should launch, in registration order.
     *
     * @return list<PendingBootProcess>
     */
    public function launchable(): array
    {
        return array_values(array_filter(
            $this->commands,
            fn (PendingBootProcess $process): bool => $this->allows($process->name()),
        ));
    }

    /**
     * The registrations the only/except filters removed, for skip notes.
     *
     * @return list<PendingBootProcess>
     */
    public function suppressed(): array
    {
        return array_values(array_filter(
            $this->commands,
            fn (PendingBootProcess $process): bool => ! $this->allows($process->name()),
        ));
    }

    /**
     * Plan-summary labels for the launchable registrations.
     *
     * @return list<string>
     */
    public function summaryLabels(): array
    {
        return array_map(
            fn (PendingBootProcess $process): string => \in_array($process->name(), self::BUILT_IN_STREAMS, true)
                ? "{$process->name()} (replaces built-in)"
                : $process->name(),
            $this->launchable(),
        );
    }

    public function isEmpty(): bool
    {
        return $this->commands === [];
    }

    private function add(BootProcessKind $kind, string $command, ?string $name, ?RegistrationSource $source): PendingBootProcess
    {
        $command = trim($command);

        $process = new PendingBootProcess(
            $kind,
            $command,
            $name ?? $kind->defaultName($command),
            $source ?? $this->detectSource(),
        );

        if (! $this->runningInConsole) {
            return $process;
        }

        if ($process->name() === self::RESERVED_STREAM) {
            throw BootCommandException::reservedName($process->name());
        }

        $existing = $this->commands[$process->name()] ?? null;

        if ($existing === null || $process->source()->mayReplace($existing->source())) {
            $this->commands[$process->name()] = $process;
        }

        return $process;
    }

    /**
     * Walk the backtrace to the first frame outside this package and the
     * framework's facade plumbing: under vendor/ it is a package
     * registering, anywhere else it is the application.
     */
    private function detectSource(): RegistrationSource
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $file = $frame['file'] ?? null;
            $class = $frame['class'] ?? null;

            if ($file === null && $class !== null && class_exists($class)) {
                $file = (new ReflectionClass($class))->getFileName() ?: null;
            }

            if ($file === null) {
                continue;
            }

            if (str_starts_with($file, \dirname(__DIR__))) {
                continue;
            }

            if (str_contains($file, 'laravel'.DIRECTORY_SEPARATOR.'framework')) {
                continue;
            }

            return str_starts_with($file, $this->vendorPath)
                ? RegistrationSource::Vendor
                : RegistrationSource::Application;
        }

        return RegistrationSource::Application;
    }
}
