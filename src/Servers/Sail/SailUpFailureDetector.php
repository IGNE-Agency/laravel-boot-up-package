<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Services\PatternDetector;

/**
 * Classifies the output of a failed `sail up`. Check registry reachability
 * first: a boot that fails on both a real-registry pull and the local-only
 * application image needs network guidance, not a build retry.
 *
 * The registry patterns anchor on real registry hosts on purpose: a
 * never-built app image also fails with "no such host" (compose treats
 * `sail-x.y` as a registry), and that case is fixed by --build, not by
 * network guidance.
 */
final class SailUpFailureDetector
{
    private const array REGISTRY_PATTERNS = [
        'registry-1.docker.io',
        'auth.docker.io',
        'temporary failure in name resolution',
        'i/o timeout',
        'tls handshake timeout',
        'proxyconnect tcp',
    ];

    private const array MISSING_IMAGE_PATTERNS = [
        'failed to resolve reference "sail-',
        'pull access denied',
        'repository does not exist',
    ];

    public function isRegistryUnreachable(string $output): bool
    {
        return PatternDetector::matchesAny($output, self::REGISTRY_PATTERNS);
    }

    public function isMissingLocalImage(string $output): bool
    {
        return PatternDetector::matchesAny($output, self::MISSING_IMAGE_PATTERNS);
    }
}
