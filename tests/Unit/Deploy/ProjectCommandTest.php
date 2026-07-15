<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Deploy\ProjectCommand;
use Igne\LaravelBootUp\Deploy\ProjectCommandType;

test('named constructors set the command type', function (): void {
    expect(ProjectCommand::artisan('db:seed')->type)->toBe(ProjectCommandType::ARTISAN)
        ->and(ProjectCommand::composer('dump-autoload')->type)->toBe(ProjectCommandType::COMPOSER)
        ->and(ProjectCommand::packageManager('run build')->type)->toBe(ProjectCommandType::PACKAGE_MANAGER);
});

test('the command and optional description are kept verbatim', function (): void {
    $command = ProjectCommand::artisan('wayfinder:generate --path=resources/js', 'Generating routes...');

    expect($command->command)->toBe('wayfinder:generate --path=resources/js')
        ->and($command->description)->toBe('Generating routes...')
        ->and(ProjectCommand::artisan('db:seed')->description)->toBeNull();
});

test('valid commands pass validation', function (string $command): void {
    expect(ProjectCommand::artisan($command))->toBeInstanceOf(ProjectCommand::class)
        ->and(ProjectCommand::composer($command))->toBeInstanceOf(ProjectCommand::class)
        ->and(ProjectCommand::packageManager($command))->toBeInstanceOf(ProjectCommand::class);
})->with([
    'artisan command with option' => 'wayfinder:generate --path=resources/js',
    'composer script' => 'dump-autoload --optimize',
    'package manager script' => 'run zodgen',
    'migrate with flags' => 'migrate --force --seed',
]);

test('an empty command is rejected', function (string $command): void {
    ProjectCommand::artisan($command);
})->with(['empty' => '', 'whitespace only' => '   '])
    ->throws(InvalidArgumentException::class, 'cannot be empty');

test('shell metacharacters are rejected because commands run as argument lists', function (string $command): void {
    ProjectCommand::artisan($command);
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
])->throws(InvalidArgumentException::class, 'shell metacharacters');

test('dangerous commands are rejected on word boundaries', function (string $command): void {
    ProjectCommand::artisan($command);
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
])->throws(InvalidArgumentException::class, 'blocked word');

test('blocked words embedded in larger words are not rejected', function (string $command): void {
    expect(ProjectCommand::artisan($command))->toBeInstanceOf(ProjectCommand::class);
})->with([
    'execute is not exec' => 'php artisan execute-thing',
    'execute-thing alone' => 'execute-thing',
    'confirm contains rm' => 'confirm:users --force',
    'skill contains kill' => 'skill:check',
    'sudoku contains sudo' => 'sudoku:solve',
    'evaluate contains eval' => 'evaluate-reports',
    'formatter contains format' => 'formatter:run',
]);
