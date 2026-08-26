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
 *
 * Port conflicts are the pre-flight's backstop: PortGuard normally catches
 * them before anything is created, but it stands down when the compose config
 * cannot be read, and nothing stops a port being taken in between.
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

    private const array PORT_CONFLICT_PATTERNS = [
        'ports are not available',
        'address already in use',
        'port is already allocated',
        'failed programming external connectivity',
    ];

    /**
     * The bind address compose names in its own error, so the port can be
     * pulled out of "listen tcp 0.0.0.0:3306: bind: address already in use".
     */
    private const string BOUND_PORT = '/(?:\d{1,3}(?:\.\d{1,3}){3}|\[?::\]?|\*):(\d{1,5})\b/';

    public function isRegistryUnreachable(string $output): bool
    {
        return PatternDetector::matchesAny($output, self::REGISTRY_PATTERNS);
    }

    public function isMissingLocalImage(string $output): bool
    {
        return PatternDetector::matchesAny($output, self::MISSING_IMAGE_PATTERNS);
    }

    public function isPortConflict(string $output): bool
    {
        return PatternDetector::matchesAny($output, self::PORT_CONFLICT_PATTERNS);
    }

    /**
     * Every host port named in the failure, in the order compose reported
     * them. Empty when the message names none -- the caller still knows it
     * was a port conflict, just not which one.
     *
     * @return list<int>
     */
    public function portsIn(string $output): array
    {
        preg_match_all(self::BOUND_PORT, $output, $matches);

        $ports = array_map(static fn (string $port): int => (int) $port, $matches[1]);

        // Compose names the container side as ":0" in the same sentence, so
        // the impossible ports have to go before the list is deduplicated.
        return array_values(array_unique(array_filter(
            $ports,
            static fn (int $port): bool => $port > 0 && $port < 65536,
        )));
    }
}
