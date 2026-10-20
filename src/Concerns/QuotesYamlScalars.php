<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Illuminate\Support\Str;

/**
 * Renders generated YAML values: a value that would not survive as a plain
 * scalar is single-quoted. Normally a no-op — generated commands avoid
 * leading indicators, ": " and " #" on purpose — but project-supplied
 * commands pass through here too.
 */
trait QuotesYamlScalars
{
    protected function yamlListItem(string $value): string
    {
        return '- '.$this->yamlScalar($value);
    }

    protected function yamlScalar(string $value): string
    {
        $unsafeFirst = $value === '' || str_contains('#&*!|>%@`"\'{}[],', $value[0]);
        $unsafeStart = Str::startsWith($value, ['- ', '? ', ': ']);
        $unsafeInside = str_contains($value, ': ') || str_contains($value, ' #') || str_ends_with($value, ':');

        if (! $unsafeFirst && ! $unsafeStart && ! $unsafeInside) {
            return $value;
        }

        return "'".str_replace("'", "''", $value)."'";
    }
}
