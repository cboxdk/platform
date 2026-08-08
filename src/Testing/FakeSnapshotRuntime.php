<?php

declare(strict_types=1);

namespace Cbox\Platform\Testing;

use Cbox\Platform\Contracts\SnapshotRuntime;

/**
 * A snapshot runtime you can point either way, for testing the tier a compile
 * lands on without pulling in a real runtime's vocabulary.
 *
 * The tier a service compiles to is the single most consequential thing a
 * target decides — the two are mutually exclusive, so a wrong answer does not
 * degrade scale-to-zero, it switches it off — which is why it is worth being
 * able to assert on both from a consumer's own suite.
 */
class FakeSnapshotRuntime implements SnapshotRuntime
{
    /** @var list<array{container: string, port: int, idleTimeoutSeconds: int}> */
    public array $annotated = [];

    /**
     * @param  array<string, string>  $annotations
     * @param  array<string, string>  $environment
     */
    public function __construct(
        private readonly bool $available = true,
        private readonly ?string $runtimeClassName = 'fake-snapshot',
        private readonly array $annotations = ['cbox.test/checkpoint' => 'true'],
        private readonly array $environment = [],
    ) {}

    /** The other tier: a wake is a real pod start. */
    public static function unavailable(): self
    {
        return new self(available: false, runtimeClassName: null, annotations: []);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function runtimeClassName(): ?string
    {
        return $this->runtimeClassName;
    }

    public function annotations(string $containerName, int $port, int $idleTimeoutSeconds): array
    {
        $this->annotated[] = [
            'container' => $containerName,
            'port' => $port,
            'idleTimeoutSeconds' => $idleTimeoutSeconds,
        ];

        return $this->annotations;
    }

    public function environment(): array
    {
        return $this->environment;
    }
}
