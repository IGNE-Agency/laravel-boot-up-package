<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\RegistrationSource;

test('application code always wins, vendor never displaces it', function (): void {
    expect(RegistrationSource::Application->mayReplace(RegistrationSource::Application))->toBeTrue()
        ->and(RegistrationSource::Application->mayReplace(RegistrationSource::Vendor))->toBeTrue()
        ->and(RegistrationSource::Vendor->mayReplace(RegistrationSource::Vendor))->toBeTrue()
        ->and(RegistrationSource::Vendor->mayReplace(RegistrationSource::Application))->toBeFalse();
});
