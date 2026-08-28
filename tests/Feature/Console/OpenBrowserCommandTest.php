<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Console\OpenBrowserCommand;
use Igne\LaravelBootUp\Enums\OperatingSystem;
use Igne\LaravelBootUp\Frontend\ViteHotFile;
use Igne\LaravelBootUp\Services\Platform;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-open-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
    $this->hot = $this->workDir.'/hot';

    app()->instance(ViteHotFile::class, new ViteHotFile($this->hot));

    // Fixed so the assertions are about the wait, not about the host running it.
    app()->instance(Platform::class, new Platform(OperatingSystem::Darwin));

    // A one-second ceiling keeps the give-up paths honest without slowing the
    // suite down; 50ms is the configured floor for the interval.
    config()->set('boot-up.setup.browser.wait_timeout_seconds', 1);
    config()->set('boot-up.setup.browser.poll_interval_ms', 50);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

test('opens the URL once something answers it', function (): void {
    ProcessFaker::fake(['curl*' => Process::result(output: '200')]);

    $this->artisan(OpenBrowserCommand::NAME, ['url' => 'https://app.test'])
        ->expectsOutputToContain('The application answered')
        ->assertSuccessful();

    ProcessFaker::assertRan('open https://app.test');
});

test('waits for Vite to write its hot file before opening', function (): void {
    ProcessFaker::fake(['curl*' => Process::result(output: '200')]);
    file_put_contents($this->hot, 'http://[::1]:5173');

    $this->artisan(OpenBrowserCommand::NAME, ['url' => 'https://app.test', '--vite' => true])
        ->expectsOutputToContain('The application answered')
        ->assertSuccessful();

    ProcessFaker::assertRan('open https://app.test');
});

test('opens anyway when Vite never comes up, and says so', function (): void {
    // The 500 the report was about: the server answers, the manifest is
    // missing. A page that needs one reload still beats no window at all.
    ProcessFaker::fake(['curl*' => Process::result(output: '500')]);

    $this->artisan(OpenBrowserCommand::NAME, ['url' => 'https://app.test', '--vite' => true])
        ->expectsOutputToContain('did not come up within 1s')
        ->assertSuccessful();

    ProcessFaker::assertRan('open https://app.test');
});

test('opens anyway when nothing ever answers, and says so', function (): void {
    ProcessFaker::fake(['curl*' => Process::result(output: '000', exitCode: 7)]);

    $this->artisan(OpenBrowserCommand::NAME, ['url' => 'https://app.test'])
        ->expectsOutputToContain('Nothing answered at https://app.test')
        ->assertSuccessful();

    ProcessFaker::assertRan('open https://app.test');
});

test('a machine without curl skips the gate instead of stalling on it', function (): void {
    ProcessFaker::fake(['sh -c command -v*curl*' => Process::result(exitCode: 1)]);

    $this->artisan(OpenBrowserCommand::NAME, ['url' => 'https://app.test'])->assertSuccessful();

    ProcessFaker::assertDidntRun('curl*');
    ProcessFaker::assertRan('open https://app.test');
});

test('the hot-file wait is skipped entirely without the flag', function (): void {
    ProcessFaker::fake(['curl*' => Process::result(output: '200')]);

    // No hot file exists and none is waited for: a run with no asset watcher
    // has nothing to wait for.
    $this->artisan(OpenBrowserCommand::NAME, ['url' => 'https://app.test'])
        ->expectsOutputToContain('The application answered')
        ->assertSuccessful();
});

test('it stays out of php artisan list', function (): void {
    // app:up's own machinery: callable by name, not worth offering to type.
    expect(Artisan::all()[OpenBrowserCommand::NAME]->isHidden())->toBeTrue();
});
