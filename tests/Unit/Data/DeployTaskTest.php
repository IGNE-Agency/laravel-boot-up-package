<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\DeployTask;
use Igne\LaravelBootUp\Enums\DeployTaskType;
use Igne\LaravelBootUp\Exceptions\DeployException;

test('named constructors set the command type', function (): void {
    expect(DeployTask::artisan('db:seed')->type)->toBe(DeployTaskType::Artisan)
        ->and(DeployTask::composer('dump-autoload')->type)->toBe(DeployTaskType::Composer)
        ->and(DeployTask::packageManager('run build')->type)->toBe(DeployTaskType::PackageManager);
});

test('the command and optional description are kept verbatim', function (): void {
    $command = DeployTask::artisan('wayfinder:generate --path=resources/js', 'Generating routes...');

    expect($command->command)->toBe('wayfinder:generate --path=resources/js')
        ->and($command->description)->toBe('Generating routes...')
        ->and(DeployTask::artisan('db:seed')->description)->toBeNull();
});

test('valid commands pass validation', function (string $command): void {
    expect(DeployTask::artisan($command))->toBeInstanceOf(DeployTask::class)
        ->and(DeployTask::composer($command))->toBeInstanceOf(DeployTask::class)
        ->and(DeployTask::packageManager($command))->toBeInstanceOf(DeployTask::class);
})->with([
    'artisan command with option' => 'wayfinder:generate --path=resources/js',
    'composer script' => 'dump-autoload --optimize',
    'package manager script' => 'run zodgen',
    'migrate with flags' => 'migrate --force --seed',
]);

test('an empty command is rejected', function (string $command): void {
    DeployTask::artisan($command);
})->with(['empty' => '', 'whitespace only' => '   '])
    ->throws(DeployException::class, 'cannot be empty');

test('shell metacharacters are rejected because commands run as argument lists', function (string $command): void {
    DeployTask::artisan($command);
})->with([
    'chaining with &&' => 'db:seed && rm -rf /',
    'chaining with ;' => 'db:seed; migrate:fresh',
    'chaining with ||' => 'db:seed || migrate:fresh',
    'background with &' => 'queue:work &',
    'pipe' => 'route:list | tee routes.txt',
    'backtick substitution' => 'db:seed `whoami`',
    'dollar substitution' => 'db:seed $(whoami)',
    'output redirect' => 'db:seed > /dev/null',
    'input redirect' => 'db:seed < seeds.sql',
    'newline' => "db:seed\nmigrate:fresh",
])->throws(DeployException::class, 'shell metacharacters');

test('dangerous commands are rejected on word boundaries', function (string $command): void {
    DeployTask::artisan($command);
})->with([
    'rm' => 'rm -rf /',
    'sudo' => 'sudo su',
    'kill' => 'kill -9 1234',
    'pkill' => 'pkill php',
    'shutdown' => 'shutdown -h now',
    'reboot' => 'reboot',
    'dd' => 'dd if=/dev/zero of=/dev/sda',
    'mkfs variant' => 'mkfs.ext4 /dev/sda1',
    'eval' => 'eval something',
    'exec' => 'exec something',
    'dangerous word mid-command' => 'db:seed --then rm -rf /',
])->throws(DeployException::class, 'blocked word');

test('blocked words embedded in larger words are not rejected', function (string $command): void {
    expect(DeployTask::artisan($command))->toBeInstanceOf(DeployTask::class);
})->with([
    'execute is not exec' => 'php artisan execute-thing',
    'execute-thing alone' => 'execute-thing',
    'confirm contains rm' => 'confirm:users --force',
    'skill contains kill' => 'skill:check',
    'sudoku contains sudo' => 'sudoku:solve',
    'evaluate contains eval' => 'evaluate-reports',
    'formatter contains format' => 'formatter:run',
]);

test('shellLine renders each type with the caller-supplied binaries', function (): void {
    expect(DeployTask::artisan('nova:publish')->shellLine('$FORGE_PHP artisan', '$FORGE_COMPOSER', 'bun'))
        ->toBe('$FORGE_PHP artisan nova:publish')
        ->and(DeployTask::composer('dump-autoload')->shellLine('php artisan', 'composer', 'bun'))
        ->toBe('composer dump-autoload')
        ->and(DeployTask::packageManager('run lint')->shellLine('php artisan', 'composer', 'pnpm'))
        ->toBe('pnpm run lint');
});
