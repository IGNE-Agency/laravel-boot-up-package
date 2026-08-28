<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\Artisan\ArtisanServer;
use Igne\LaravelBootUp\Servers\Herd\HerdServer;
use Igne\LaravelBootUp\Servers\Sail\SailServer;
use Igne\LaravelBootUp\Servers\ServerSelector;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ValetServer;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Process::fake();

    $this->workDir = sys_get_temp_dir().'/boot-up-selector-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);

    app()->singleton(ProcessLedger::class, fn (): ProcessLedger => new ProcessLedger($this->workDir.'/processes.json'));
    app()->singleton(ProcessRunner::class, fn (): ProcessRunner => new ProcessRunner(
        processes: app(Factory::class),
        ledger: app(ProcessLedger::class),
        logDirectory: $this->workDir.'/logs',
    ));
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function serverSelector(?string $default = null, bool $prompt = true, ?ActiveServerStore $store = null): ServerSelector
{
    return new ServerSelector(
        app(),
        new DevServerConfig(default: $default, prompt: $prompt),
        $store ?? activeServerStore(),
    );
}

function rememberedServer(string $key): ActiveServerStore
{
    $store = activeServerStore();
    $store->remember(new ActiveServerRecord($key, true, 4242, date(DATE_ATOM)));

    return $store;
}

test('an explicit argument resolves its driver, case-insensitively', function (): void {
    expect(serverSelector()->select('herd'))->toBeInstanceOf(HerdServer::class)
        ->and(serverSelector()->select('SAIL'))->toBeInstanceOf(SailServer::class);
});

test('an unknown argument throws', function (): void {
    expect(fn () => serverSelector()->select('caddy'))
        ->toThrow(ServerException::class, 'caddy');
});

test('the configured default wins when no argument is given', function (): void {
    expect(serverSelector(default: 'sail')->select(null))->toBeInstanceOf(SailServer::class);
});

test('an unknown configured default throws', function (): void {
    expect(fn () => serverSelector(default: 'caddy')->select(null))
        ->toThrow(ServerException::class, 'caddy');
});

test('prompting disabled without a default falls back to the laravel driver', function (): void {
    $server = serverSelector(prompt: false)->select(null);

    expect($server)->toBeInstanceOf(ArtisanServer::class)
        ->and($server->key())->toBe('artisan');
});

test('prompts a select over the driver labels when nothing is preconfigured', function (): void {
    Prompt::fake([Key::DOWN, Key::ENTER]);

    $server = serverSelector()->select(null);

    expect($server)->toBeInstanceOf(SailServer::class);
    Prompt::assertStrippedOutputContains('Laravel Herd');
    Prompt::assertStrippedOutputContains('Laravel Sail');
    Prompt::assertStrippedOutputContains('Laravel (php artisan serve)');
});

test('driver resolves project-registered drivers from config', function (): void {
    config()->set('boot-up.server.drivers', ['valet' => ValetServer::class]);

    $selector = new ServerSelector(app(), DevServerConfig::fromRepository(config()), activeServerStore());

    expect($selector->driver('valet'))->toBeInstanceOf(ValetServer::class)
        ->and($selector->select('valet'))->toBeInstanceOf(ValetServer::class)
        ->and($selector->driver('herd'))->toBeInstanceOf(HerdServer::class);
});

test('driver throws for unknown keys', function (): void {
    expect(fn () => serverSelector()->driver('caddy'))
        ->toThrow(ServerException::class, 'caddy');
});

test('remembered resolves the driver app:up recorded', function (): void {
    $selector = serverSelector(store: rememberedServer('sail'));

    expect($selector->remembered(null))->toBeInstanceOf(SailServer::class);
});

test('remembered prefers an explicit argument over the record', function (): void {
    $selector = serverSelector(store: rememberedServer('sail'));

    expect($selector->remembered('herd'))->toBeInstanceOf(HerdServer::class);
});

test('remembered falls back to the configured default when nothing was recorded', function (): void {
    expect(serverSelector(default: 'sail')->remembered(null))->toBeInstanceOf(SailServer::class);
});

test('remembered is null when nothing on this machine says which server serves the project', function (): void {
    // Never a silent 'artisan' fallback: that would bind port 8000 for a
    // project Herd already serves.
    expect(serverSelector()->remembered(null))->toBeNull();
});

test('remembered warns and falls through when the record names an unknown driver', function (): void {
    Prompt::fake();

    $selector = serverSelector(default: 'herd', store: rememberedServer('caddy'));

    expect($selector->remembered(null))->toBeInstanceOf(HerdServer::class);
    Prompt::assertStrippedOutputContains('The recorded server [caddy] is not a known driver');
});

test('remembered never prompts, even with prompting enabled and no record', function (): void {
    // A prompt here would hang `dev` in a pipeline; nothing to pick means
    // nothing to run.
    expect(serverSelector(prompt: true)->remembered(null))->toBeNull();
});
