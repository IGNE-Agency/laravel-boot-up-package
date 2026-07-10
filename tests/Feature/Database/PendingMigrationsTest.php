<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Database\PendingMigrations;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bootstrap-pending-migrations-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    $stub = <<<'PHP'
    <?php

    use Illuminate\Database\Migrations\Migration;

    return new class extends Migration
    {
        public function up(): void {}

        public function down(): void {}
    };
    PHP;

    file_put_contents($this->dir.'/2024_01_01_000000_create_dummies_table.php', $stub);
    file_put_contents($this->dir.'/2024_01_01_000001_create_widgets_table.php', $stub);

    $this->migrator = app('migrator');
    $this->migrator->path($this->dir);

    $this->pending = new PendingMigrations($this->migrator);
});

afterEach(function (): void {
    if (is_dir($this->dir)) {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
});

test('every migration is pending while the repository does not exist', function (): void {
    expect($this->migrator->repositoryExists())->toBeFalse()
        ->and($this->pending->pending())->toBe([
            '2024_01_01_000000_create_dummies_table',
            '2024_01_01_000001_create_widgets_table',
        ])
        ->and($this->pending->count())->toBe(2);
});

test('an empty repository still reports every migration as pending', function (): void {
    app('migration.repository')->createRepository();

    expect($this->migrator->repositoryExists())->toBeTrue()
        ->and($this->pending->count())->toBe(2);
});

test('a ran migration is no longer pending', function (): void {
    app('migration.repository')->createRepository();

    $this->migrator->getRepository()->log('2024_01_01_000000_create_dummies_table', 1);

    expect($this->pending->pending())->toBe(['2024_01_01_000001_create_widgets_table'])
        ->and($this->pending->count())->toBe(1);
});

test('nothing is pending when everything ran', function (): void {
    app('migration.repository')->createRepository();

    $this->migrator->getRepository()->log('2024_01_01_000000_create_dummies_table', 1);
    $this->migrator->getRepository()->log('2024_01_01_000001_create_widgets_table', 1);

    expect($this->pending->pending())->toBe([])
        ->and($this->pending->count())->toBe(0);
});
