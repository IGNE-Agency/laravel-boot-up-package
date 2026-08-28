<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

/**
 * A reserved port that turned out to be taken, and what was holding it.
 */
final readonly class PortConflict
{
    /**
     * Docker's own port forwarder shows up as the holder when the clash is
     * with another project's containers -- the single most likely cause on a
     * machine running more than one Sail project, and worth naming.
     */
    private const string DOCKER_HOLDER = '/docker|vpnkit/i';

    public function __construct(
        public ReservedPort $port,
        public ?string $holder = null,
    ) {}

    /**
     * What is on the port and who has it, with no remedy attached — the half
     * that reads the same whether the guard is about to explain or to offer a
     * move.
     */
    public function held(): string
    {
        $line = "{$this->port->port} ({$this->port->purpose})";

        if ($this->holder === null) {
            return $line;
        }

        $line .= " — held by {$this->holder}";

        if (preg_match(self::DOCKER_HOLDER, $this->holder) === 1) {
            $line .= ', most likely another project\'s containers (stop them with `sail down` there)';
        }

        return $line;
    }

    /**
     * One line, complete with its remedy: what is on the port, who has it,
     * and how to get it back.
     */
    public function describe(): string
    {
        return "{$this->held()}. Free it, or {$this->port->remedy()}.";
    }
}
