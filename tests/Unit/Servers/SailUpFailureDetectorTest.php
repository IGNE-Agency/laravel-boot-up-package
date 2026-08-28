<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Servers\Sail\SailUpFailureDetector;

const SAIL_DNS_FAILURE = 'Error response from daemon: failed to resolve reference "docker.io/library/mysql:8.4": '
    .'failed to do request: Head "https://registry-1.docker.io/v2/library/mysql/manifests/8.4": '
    .'dialing registry-1.docker.io:443 container via direct connection because Docker Desktop has no HTTPS proxy: '
    .'connecting to registry-1.docker.io:443: dial tcp: lookup registry-1.docker.io: no such host';

const SAIL_MISSING_IMAGE = 'Image sail-8.5/app failed to resolve reference "sail-8.5/app:latest": '
    .'failed to do request: Head "https://sail-8.5/v2/app/manifests/latest": '
    .'dialing sail-8.5:443 container via direct connection because Docker Desktop has no HTTPS proxy: '
    .'connecting to sail-8.5:443: dial tcp: lookup sail-8.5: no such host';

const SAIL_PORT_CLASH = 'Error response from daemon: ports are not available: '
    .'exposing port TCP 0.0.0.0:3306 -> 127.0.0.1:0: listen tcp 0.0.0.0:3306: bind: address already in use';

test('recognises an unreachable registry from real compose output', function (): void {
    $detector = new SailUpFailureDetector;

    expect($detector->isRegistryUnreachable(SAIL_DNS_FAILURE))->toBeTrue()
        ->and($detector->isRegistryUnreachable('net/http: TLS handshake timeout'))->toBeTrue()
        ->and($detector->isRegistryUnreachable('Temporary failure in name resolution'))->toBeTrue();
});

test('recognises a never-built application image', function (): void {
    $detector = new SailUpFailureDetector;

    expect($detector->isMissingLocalImage(SAIL_MISSING_IMAGE))->toBeTrue()
        ->and($detector->isMissingLocalImage('pull access denied for sail-8.5/app'))->toBeTrue();
});

test('a missing app image is not mistaken for a network problem', function (): void {
    // Its "no such host" is compose treating `sail-8.5` as a registry —
    // the fix is --build, not network guidance.
    $detector = new SailUpFailureDetector;

    expect($detector->isRegistryUnreachable(SAIL_MISSING_IMAGE))->toBeFalse()
        ->and($detector->isMissingLocalImage(SAIL_DNS_FAILURE))->toBeFalse();
});

test('a boot failing on both pulls classifies as registry first', function (): void {
    $detector = new SailUpFailureDetector;
    $combined = SAIL_DNS_FAILURE."\n".SAIL_MISSING_IMAGE;

    expect($detector->isRegistryUnreachable($combined))->toBeTrue()
        ->and($detector->isMissingLocalImage($combined))->toBeTrue();
});

test('an unrelated compose error matches neither signature', function (): void {
    $detector = new SailUpFailureDetector;
    $output = 'yaml: line 12: mapping values are not allowed in this context';

    expect($detector->isRegistryUnreachable($output))->toBeFalse()
        ->and($detector->isMissingLocalImage($output))->toBeFalse()
        ->and($detector->isPortConflict($output))->toBeFalse();
});

test('recognises a taken host port from real daemon output', function (): void {
    $detector = new SailUpFailureDetector;

    expect($detector->isPortConflict(SAIL_PORT_CLASH))->toBeTrue()
        ->and($detector->isPortConflict('Bind for 0.0.0.0:80 failed: port is already allocated'))->toBeTrue()
        ->and($detector->isPortConflict('driver failed programming external connectivity on endpoint'))->toBeTrue();
});

test('the clashing port is recovered from the message', function (): void {
    $detector = new SailUpFailureDetector;

    // The container side is named as ":0" in the same sentence, and the host
    // side twice — one port comes back, not three.
    expect($detector->portsIn(SAIL_PORT_CLASH))->toBe([3306])
        ->and($detector->portsIn('Bind for 0.0.0.0:80 failed: port is already allocated'))->toBe([80])
        ->and($detector->portsIn('listen tcp [::]:5173: bind: address already in use'))->toBe([5173])
        ->and($detector->portsIn('ports are not available'))->toBe([]);
});

test('a port clash is not mistaken for a registry or image problem', function (): void {
    $detector = new SailUpFailureDetector;

    expect($detector->isRegistryUnreachable(SAIL_PORT_CLASH))->toBeFalse()
        ->and($detector->isMissingLocalImage(SAIL_PORT_CLASH))->toBeFalse()
        ->and($detector->isPortConflict(SAIL_DNS_FAILURE))->toBeFalse()
        ->and($detector->isPortConflict(SAIL_MISSING_IMAGE))->toBeFalse();
});
