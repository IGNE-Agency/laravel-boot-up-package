<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\UrlProbe;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;

function urlProbe(): UrlProbe
{
    return new UrlProbe(new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger(sys_get_temp_dir().'/boot-up-probe-'.bin2hex(random_bytes(4)).'.json'),
        logDirectory: sys_get_temp_dir(),
    ));
}

test('any HTTP status counts as answering', function (): void {
    // A Vite manifest exception is a 500, and it proves the server answered:
    // that is exactly the state the browser is waiting to get past, not the
    // state it is waiting for.
    ProcessFaker::fake(['curl*' => Process::result(output: '500')]);

    expect(urlProbe()->isAnswering('https://app.test'))->toBeTrue();
});

test('a refused connection is not answering', function (): void {
    ProcessFaker::fake(['curl*' => Process::result(output: '000', exitCode: 7)]);

    expect(urlProbe()->isAnswering('https://app.test'))->toBeFalse();
});

test('a zero exit with no status is not answering', function (): void {
    ProcessFaker::fake(['curl*' => Process::result(output: '')]);

    expect(urlProbe()->isAnswering('https://app.test'))->toBeFalse();
});

test('the probe trusts Herd\'s self-signed certificate and leaves retrying to the caller', function (): void {
    ProcessFaker::fake(['curl*' => Process::result(output: '200')]);

    urlProbe()->isAnswering('https://app.test');

    // --insecure is required for a Herd-secured .test; --retry is not wanted,
    // because the poll loop is the retry and curl's own would overrun its
    // interval. Both are the contract, so both are asserted.
    ProcessFaker::assertRan('curl --silent --insecure --output /dev/null*https://app.test');
    ProcessFaker::assertDidntRun('curl*--retry*');
});

test('availability is decided once, however often the probe is asked', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: '200')]);

    $probe = urlProbe();

    expect($probe->isAvailable())->toBeTrue()
        ->and($probe->isAvailable())->toBeTrue();

    ProcessFaker::assertRanTimes('sh -c command -v*curl*', 1);
});
