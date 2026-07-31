<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\AssetMode;

test('the default is watch', function (): void {
    expect(AssetMode::default())->toBe(AssetMode::Watch);
});

test('null and the empty string mean the default', function (): void {
    expect(AssetMode::fromConfig(null, 'boot-up.frontend.assets'))->toBe(AssetMode::default())
        ->and(AssetMode::fromConfig('', 'boot-up.frontend.assets'))->toBe(AssetMode::default());
});
