<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\EnvRestorePoint;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->dir = sys_get_temp_dir().'/boot-up-env-restore-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    $this->envPath = $this->dir.'/.env';
    $this->statePath = $this->dir.'/env-restore.json';

    $this->envFile = new EnvFile($this->envPath, $this->dir.'/.env.example');
    $this->restore = new EnvRestorePoint($this->envFile, $this->statePath);

    file_put_contents($this->envPath, implode(PHP_EOL, [
        'APP_NAME=Dashboard',
        'DB_CONNECTION=mysql',
        'DB_HOST=127.0.0.1',
        'DB_USERNAME=root',
        'DB_PASSWORD=secret',
        '',
    ]));
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * What `php artisan sail:install` does to the .env: rewrite the database
 * credentials for its containers, and append settings for the services it
 * scaffolded.
 */
function sailInstallWould(EnvFile $envFile): Closure
{
    return function () use ($envFile): void {
        $envFile->setMany([
            'DB_HOST' => 'mysql',
            'DB_USERNAME' => 'sail',
            'DB_PASSWORD' => 'password',
            'REDIS_HOST' => 'redis',
        ]);
    };
}

test('what a mutation changed is put back, and what it added is removed', function (): void {
    $this->restore->around(sailInstallWould($this->envFile));

    expect($this->envFile->get('DB_USERNAME'))->toBe('sail')
        ->and($this->envFile->get('REDIS_HOST'))->toBe('redis');

    $this->restore->restore();

    expect($this->envFile->get('DB_HOST'))->toBe('127.0.0.1')
        ->and($this->envFile->get('DB_USERNAME'))->toBe('root')
        ->and($this->envFile->get('DB_PASSWORD'))->toBe('secret')
        // Absent before the boot, so putting it back means taking it out.
        ->and($this->envFile->get('REDIS_HOST'))->toBeNull()
        ->and($this->envFile->get('APP_NAME'))->toBe('Dashboard');
});

test('the restore point is spent once it has been used', function (): void {
    $this->restore->around(sailInstallWould($this->envFile));
    $this->restore->restore();

    $this->envFile->set('DB_USERNAME', 'sail');
    $this->restore->restore();

    expect(is_file($this->statePath))->toBeFalse()
        ->and($this->envFile->get('DB_USERNAME'))->toBe('sail');
});

test('restoring nothing says nothing', function (): void {
    $this->restore->restore();

    expect((string) file_get_contents($this->envPath))->toContain('DB_USERNAME=root');
    Prompt::assertOutputDoesntContain('Restored');
});

test('the value from before the boot wins over the one in between', function (): void {
    // Two steps writing the same key: sail:install first, then the
    // credentials step reconciling it.
    $this->restore->around(sailInstallWould($this->envFile));
    $this->restore->around(fn () => $this->envFile->set('DB_USERNAME', 'forge'));

    $this->restore->restore();

    expect($this->envFile->get('DB_USERNAME'))->toBe('root');
});

test('a value edited by hand since is left alone and reported', function (): void {
    $this->restore->around(sailInstallWould($this->envFile));

    // The user changes it themselves while the session runs.
    $this->envFile->set('DB_PASSWORD', 'my-own-password');

    $this->restore->restore();

    expect($this->envFile->get('DB_PASSWORD'))->toBe('my-own-password')
        // Everything it did not touch is still restored.
        ->and($this->envFile->get('DB_USERNAME'))->toBe('root');
    Prompt::assertStrippedOutputContains('Left DB_PASSWORD alone in .env');
});

test('a mutation that throws is still recorded', function (): void {
    // Half-applied is exactly when the undo matters most.
    expect(fn () => $this->restore->around(function (): void {
        $this->envFile->set('DB_HOST', 'mysql');

        throw new RuntimeException('sail:install blew up');
    }))->toThrow(RuntimeException::class);

    $this->restore->restore();

    expect($this->envFile->get('DB_HOST'))->toBe('127.0.0.1');
});

test('a mutation that changes nothing records nothing', function (): void {
    $this->restore->around(fn () => $this->envFile->set('DB_USERNAME', 'root'));

    expect(is_file($this->statePath))->toBeFalse();
});

test('a key the mutation deleted is written back', function (): void {
    $this->restore->around(fn () => $this->envFile->remove(['DB_PASSWORD']));

    expect($this->envFile->get('DB_PASSWORD'))->toBeNull();

    $this->restore->restore();

    expect($this->envFile->get('DB_PASSWORD'))->toBe('secret');
});

test('forget drops the record without touching the .env', function (): void {
    $this->restore->around(sailInstallWould($this->envFile));
    $this->restore->forget();
    $this->restore->restore();

    expect($this->envFile->get('DB_USERNAME'))->toBe('sail')
        ->and(is_file($this->statePath))->toBeFalse();
});

test('a corrupt record is quarantined rather than obeyed', function (): void {
    file_put_contents($this->statePath, '{"DB_USERNAME": "root"}');

    $this->restore->restore();

    expect($this->envFile->get('DB_USERNAME'))->toBe('root')
        ->and(is_file($this->statePath.'.corrupt'))->toBeTrue();
    Prompt::assertStrippedOutputContains('restore point was corrupt');
});

test('the record holds only the keys that changed', function (): void {
    file_put_contents($this->envPath, "DB_PASSWORD=secret\nSTRIPE_SECRET=sk_live_abc\n");

    $this->restore->around(fn () => $this->envFile->set('DB_PASSWORD', 'password'));

    // It is as sensitive as the .env, so it must carry no more of it than the
    // undo actually needs.
    expect(array_keys((array) json_decode((string) file_get_contents($this->statePath), true)))
        ->toBe(['DB_PASSWORD']);
});

test('the record is readable only by the user who owns it', function (): void {
    $this->restore->around(sailInstallWould($this->envFile));

    expect(fileperms($this->statePath) & 0777)->toBe(0600);
});

test('the state directory is created ignored by git', function (): void {
    $nested = $this->dir.'/framework/boot-up';
    $restore = new EnvRestorePoint($this->envFile, $nested.'/env-restore.json');

    $restore->around(sailInstallWould($this->envFile));

    expect(file_get_contents($nested.'/.gitignore'))->toBe("*\n!.gitignore\n");
});

test('the record survives into another process', function (): void {
    $this->restore->around(sailInstallWould($this->envFile));

    // app:down runs in a process of its own, so a fresh instance over the
    // same files has to be able to finish the job.
    (new EnvRestorePoint($this->envFile, $this->statePath))->restore();

    expect($this->envFile->get('DB_USERNAME'))->toBe('root');
});
