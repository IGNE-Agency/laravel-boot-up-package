<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\DevSession;

test('a dev session belongs to nobody until a command claims it', function (): void {
    expect((new DevSession)->isClaimed())->toBeFalse();
});

test('claiming is what tells dev to hand control back when the terminal quits', function (): void {
    $session = new DevSession;

    $session->claim();

    expect($session->isClaimed())->toBeTrue();
});
