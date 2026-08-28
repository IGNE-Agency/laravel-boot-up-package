<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Environment;

use Closure;
use Igne\LaravelBootUp\Services\JsonStore;

/**
 * Undo for the .env changes that belong to one boot rather than to the
 * project.
 *
 * Serving with Sail rewrites the environment: `sail:install` points DB_HOST
 * at a container, DB_USERNAME at `sail`, DB_PASSWORD at `password`, and
 * REDIS_HOST / SCOUT_DRIVER at whatever services it scaffolded. Those values
 * only work while the containers run, so a boot that fails -- or a session
 * that ends -- must not leave them behind for the next `php artisan dev`
 * against a Herd site and a local database.
 *
 * around() wraps a mutation and records what it actually changed, which is
 * the only way to cover a rewrite this package does not perform itself.
 * Restoring is deliberately conservative: a value edited by hand since is
 * left alone, and the first recorded original always wins, so two writes to
 * the same key still restore to what was there before the boot.
 *
 * Persisted because app:down runs in another process entirely.
 */
final class EnvRestorePoint
{
    private const string WAS = 'was';

    private const string BECAME = 'became';

    /**
     * The record holds the .env values it is undoing, so it is as sensitive as
     * the .env itself and is written for this user only. It is also short-lived
     * — restoring deletes it.
     */
    private const int PERMISSIONS = 0600;

    private readonly JsonStore $store;

    public function __construct(private readonly EnvFile $envFile, string $path)
    {
        $this->store = new JsonStore(
            $path,
            'The boot-up .env restore point was corrupt — moved to %s and reset. Check your .env against version control.',
            self::PERMISSIONS,
        );
    }

    /**
     * Run something that writes to .env, remembering every key it changed.
     */
    public function around(Closure $mutation): mixed
    {
        $before = $this->envFile->all();

        try {
            return $mutation();
        } finally {
            $this->record($before, $this->envFile->all());
        }
    }

    /**
     * Put back what this boot changed and forget the record. Silent when
     * there is nothing to undo, so both teardown paths can call it blind.
     */
    public function restore(): void
    {
        $recorded = $this->recorded();

        if ($recorded === []) {
            return;
        }

        $restore = [];
        $remove = [];
        $edited = [];

        foreach ($recorded as $key => $change) {
            $current = $this->envFile->get($key);

            // Changed again since the boot wrote it: that later value is the
            // user's, and undoing our write would take theirs with it.
            if ($current !== $change[self::BECAME]) {
                $edited[] = $key;

                continue;
            }

            if ($change[self::WAS] === null) {
                $remove[] = $key;

                continue;
            }

            $restore[$key] = $change[self::WAS];
        }

        $this->apply($restore, $remove);
        $this->store->clear();

        if ($edited !== []) {
            terminal()->note(sprintf(
                'Left %s alone in .env — changed by hand since the boot wrote it.',
                implode(', ', $edited),
            ));
        }
    }

    public function forget(): void
    {
        $this->store->clear();
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     */
    private function record(array $before, array $after): void
    {
        $recorded = $this->recorded();

        foreach ($after as $key => $value) {
            $recorded = $this->note($recorded, $key, $before[$key] ?? null, $value);
        }

        // A key the mutation deleted is restored by writing it back, so it
        // needs a record too.
        foreach ($before as $key => $value) {
            if (! \array_key_exists($key, $after)) {
                $recorded = $this->note($recorded, $key, $value, null);
            }
        }

        if ($recorded !== []) {
            $this->store->write($recorded);
        }
    }

    /**
     * Fold one key's change into the record.
     *
     * $was is the value at the start of THIS mutation, so an unchanged key is
     * one this mutation did not write — and must not be noted, or a value the
     * user edited in between would be mistaken for one of ours.
     *
     * The original is whatever the first mutation found; `became` tracks the
     * latest value the boot itself wrote, which is what tells a later hand
     * edit apart from our own writing.
     *
     * @param  array<string, array{was: string|null, became: string|null}>  $recorded
     * @return array<string, array{was: string|null, became: string|null}>
     */
    private function note(array $recorded, string $key, ?string $was, ?string $value): array
    {
        if ($was === $value) {
            return $recorded;
        }

        if (isset($recorded[$key])) {
            $recorded[$key][self::BECAME] = $value;

            return $recorded;
        }

        $recorded[$key] = [self::WAS => $was, self::BECAME => $value];

        return $recorded;
    }

    /**
     * @return array<string, array{was: string|null, became: string|null}>
     */
    private function recorded(): array
    {
        $decoded = $this->store->read();

        if ($decoded === null) {
            return [];
        }

        $recorded = [];

        foreach ($decoded as $key => $change) {
            if (! \is_string($key) || ! \is_array($change) || ! \array_key_exists(self::WAS, $change)) {
                $this->store->quarantine();

                return [];
            }

            $recorded[$key] = [
                self::WAS => \is_string($change[self::WAS]) ? $change[self::WAS] : null,
                self::BECAME => \is_string($change[self::BECAME] ?? null) ? $change[self::BECAME] : null,
            ];
        }

        return $recorded;
    }

    /**
     * @param  array<string, string>  $restore
     * @param  list<string>  $remove
     */
    private function apply(array $restore, array $remove): void
    {
        if ($restore === [] && $remove === []) {
            return;
        }

        if ($restore !== []) {
            $this->envFile->setMany($restore);
        }

        $this->envFile->remove($remove);

        $keys = implode(', ', [...array_keys($restore), ...$remove]);

        terminal()->success("Restored {$keys} in .env.");
    }
}
